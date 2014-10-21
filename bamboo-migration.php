<?php
/**********************************************************************************************************************/
/*
	Plugin Name: Bamboo Migration
	Plugin URI:  http://www.bamboosolutions.co.uk/wordpress/bamboo-migration
	Author:      Bamboo Solutions
	Author URI:  http://www.bamboosolutions.co.uk
	Version:     1.0
	Description: Makes migrating your Wordpress website from one web address to another as simple as possible. This plugin enables you to export your MySQL database while replacing all occurences of the old web address with the new one.
*/
/**********************************************************************************************************************/

	define( 'SQL_FILENAME', 'migrate.sql' );
	define( 'ROWS_PER_SEGMENT', 100 );

/**********************************************************************************************************************/

	$bamboo_migrate_current_url = home_url();
	$bamboo_migrate_new_url 	= '';
	$bamboo_migrate_sql_path	= '';
	$bamboo_migrate_result 		= '';

/**********************************************************************************************************************/

	// Hook into init event
	add_action( 'init' , 'bamboo_migrate_init' );

/**********************************************************************************************************************/

	function bamboo_migrate_init() {

		global $bamboo_migrate_current_url, $bamboo_migrate_new_url, $bamboo_migrate_result;

		// Hook into the admin menu event
		add_action( 'admin_menu', 'bamboo_migrate_admin_menu' );

		// If the migrate form has been posted back...
		if( isset( $_POST["bamboo_migrate_submit"] ) ){

			// Get the settings
			if( isset( $_POST["bamboo_migrate_current_url"] ) ){
				$bamboo_migrate_current_url = $_POST["bamboo_migrate_current_url"];
			};
			if( isset( $_POST["bamboo_migrate_new_url"] ) ){
				$bamboo_migrate_new_url = $_POST["bamboo_migrate_new_url"];
			};

			// Execute the migration
			$bamboo_migrate_result = bamboo_migrate_exec();
			if ( 'OK'==$bamboo_migrate_result ) {
				// Setup the redirection to set the download flag
		        $url = admin_url('tools.php?page=bamboo-migrate&download=true');
		        echo "<meta http-equiv=\"refresh\" content=\"1;$url\"/>";
		    }

		}

		// If the download flag has been set send the sql file to the browser...
		if( isset( $_GET["download"] ) ) {
			$bamboo_migrate_result = 'OK';
			add_action( 'admin_init', 'bamboo_migrate_download_file');
		}

	}

/**********************************************************************************************************************/

	function bamboo_migrate_admin_menu() {

		// Add the management page to the tools admin menu
		add_management_page( 'Bamboo Migration', 'Bamboo Migration', 'manage_options', 'bamboo-migrate', 'bamboo_migrate_page' );

	}

/**********************************************************************************************************************/

	function bamboo_migrate_page() {

		global $bamboo_migrate_current_url, $bamboo_migrate_new_url, $bamboo_migrate_result;

		// If the current user lacks the required permissions to view options abort
		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		$html = '';

		// If a migration has been run output the result
		if( ''!=$bamboo_migrate_result ) {
			if( 'OK'!=$bamboo_migrate_result ) {
				$html.= '<div class="updated settings-error" id="setting-error-settings_updated">';
				$html.= '<p><strong>Migration failed:</strong></p><p>' . $bamboo_migrate_result . '</p></div>';
			} else {
				$html.= '<div class="updated settings-error" id="setting-error-settings_updated">';
				$html.= '<p><strong>Database migrated.</strong></p></div>';
			}
		}

		// Generate the plugin page
		$html .= <<<EOD

	<div class="wrap">
		<div id="icon-tools" class="icon32"></div>
		<h2>Bamboo Migration</h2><br/>
		<h4>Migrate your Wordpress site from one web address to another.</h4>
		<p>Enter the address of your new website and click 'Export Database' - the sql file for your new site should download automatically.</p>
		<form name="frmSettings" method="post">
			<table class="form-table">
				<tr>
					<th scope="row">Current address (URL)</th>
					<td><input type="text" readonly id="bamboo_migrate_current_url" name="bamboo_migrate_current_url" size="40" value="$bamboo_migrate_current_url"/></td>
				<tr></tr>
					<th scope="row">New address (URL)</label></th>
					<td><input type="text" id="bamboo_migrate_new_url" name="bamboo_migrate_new_url" size="40" value=""/></td>
				</tr>
			</table>
			<br/>
			<input type="submit" value="Export Database" class="button button-primary" id="submit" name="bamboo_migrate_submit">
		</form>
	</div>
	<script type="text/javascript">
		jQuery(document).ready(function(){
			jQuery("#bamboo_migrate_new_url").focus();
		});
	</script>
EOD;

		// Output the plugin page
		echo $html;


	}

/**********************************************************************************************************************/

	function bamboo_migrate_exec() {

		global $bamboo_migrate_current_url, $bamboo_migrate_new_url, $bamboo_migrate_sql_path;

		// Initialise result
		$result = 'OK';

		// Get the WordPress globals relating to the database
		global $table_prefix, $wpdb;

		// Establish the path to this plugin
		$path = plugin_dir_path( dirname( __FILE__ ) . '/bamboo-migrate.php' );

		// Establish the path to the sql file
		$bamboo_migrate_sql_path = $path . SQL_FILENAME;

		// Before we go any further check that the plugin folder contains the .htaccess file
		// to prevent access to the sql files via the web for security reasons
		$access_file = $path.".htaccess";
		if( !file_exists( $access_file ) ) {
			file_put_contents( $access_file, "deny from all\n" );
		}

		// Create the sql file
		file_put_contents( $bamboo_migrate_sql_path, '' );

		// Start the sql file
		bamboo_migrate_write_to_sql_file( "# **********************************************************************" );
		bamboo_migrate_write_to_sql_file( "# WordPress Database Migration" );
		bamboo_migrate_write_to_sql_file( "# From " . $bamboo_migrate_current_url . " to " . $bamboo_migrate_new_url );
		bamboo_migrate_write_to_sql_file( "# Generated: " . date("l j. F Y H:i T") );
		bamboo_migrate_write_to_sql_file( "# Hostname: " . DB_HOST );
		bamboo_migrate_write_to_sql_file( "# Database: " . bamboo_backquote( DB_NAME ) );
		bamboo_migrate_write_to_sql_file( "# **********************************************************************" );

		// Get all the tables in the database
        $tables = $wpdb->get_results( "SHOW FULL TABLES", ARRAY_N );

        // Process each table
		foreach ( $tables as $table ) {

			// Skip views
            if ( 'VIEW' == $table[1] ) continue;

            // Get table name
            $table = $table[0];

			// Increase script execution time-limit to 15 min for every table.
			if ( !ini_get('safe_mode' ) ) @set_time_limit(15*60);

			//Migrate the table to the sql file
			$result = bamboo_migrate_table_to_file( $table );
			if( 'OK'!=$result ) return $result;
		}

		return $result;
	}

/**********************************************************************************************************************/

	function bamboo_migrate_table_to_file( $table='' ) {

		global $wpdb;

		// Write the start of the sql
		bamboo_migrate_write_to_sql_file( "# **********************************************************************" );
		bamboo_migrate_write_to_sql_file( "# Table: " . bamboo_backquote( $table ) );
		bamboo_migrate_write_to_sql_file( "# **********************************************************************" );

		// Get the table structure
		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if (! $table_structure ) {
			$this->error(__('Error getting table details','wp-migrate-db' ) . ": $table");
			return "Error getting table details: " . bamboo_backquote( $table );
		}

		// Drop existing table
		bamboo_migrate_write_to_sql_file( "" );
		bamboo_migrate_write_to_sql_file( "# Delete any existing table " . bamboo_backquote( $table ) );
		bamboo_migrate_write_to_sql_file( "DROP TABLE IF EXISTS " . bamboo_backquote( $table ) . " ;");

		// Table structure
		bamboo_migrate_write_to_sql_file( "");
		bamboo_migrate_write_to_sql_file( "# Table structure of table " . bamboo_backquote( $table ) ) ;
		$create_table = $wpdb->get_results( "SHOW CREATE TABLE $table", ARRAY_N );
		if ( false === $create_table ) {
			bamboo_migrate_write_to_sql_file( "# Error with SHOW CREATE TABLE for " . $table );
			return 'Error with SHOW CREATE TABLE for ' . $table;
		}
		bamboo_migrate_write_to_sql_file( $create_table[0][1] . ' ;' );
		if ( false === $table_structure ) {
			bamboo_migrate_write_to_sql_file( "# Error getting table structure of  " . $table );
			return 'Error getting table structure of ' . $table;
		}

		// Table contents
		bamboo_migrate_write_to_sql_file( "" );
		bamboo_migrate_write_to_sql_file( '# Data contents of table ' . bamboo_backquote( $table ) );

		// Initialise arrays
		$defs = array();
		$ints = array();

		// Analyse table structure
		foreach ( $table_structure as $struct ) {

			if ( ( 0 === strpos( $struct->Type, 'tinyint' ) ) ||
				(0 === strpos(strtolower( $struct->Type ), 'smallint' ) ) ||
				(0 === strpos(strtolower( $struct->Type ), 'mediumint' ) ) ||
				(0 === strpos(strtolower( $struct->Type ), 'int' ) ) ||
				(0 === strpos(strtolower( $struct->Type ), 'bigint' ) )
				) {
					$defs[strtolower( $struct->Field )] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
					$ints[strtolower( $struct->Field )] = "1";
			}
		}

		// Batch
		$row_start = 0;
		$row_inc = ROWS_PER_SEGMENT;

		// Start processing the table data
		do {

			if ( !ini_get('safe_mode' ) ) @set_time_limit(15*60);

			// Get a segment of data
			$table_data = $wpdb->get_results( "SELECT * FROM $table LIMIT {$row_start}, {$row_inc}", ARRAY_A );

			// Prepare to process the segment of data
			$entries = 'INSERT INTO ' . bamboo_backquote( $table ) . ' VALUES (';
			$search = array("\x00", "\x0a", "\x0d", "\x1a");
			$replace = array('\0', '\n', '\r', '\Z' );

			// If we've got a segment of data
			if( $table_data ) {

				// Process each row of data
				foreach ( $table_data as $row ) {

					// Initialise the values
					$values = array();

					// Process each field
					foreach ( $row as $key => $value ) {

						if (isset( $ints[strtolower( $key )] ) && $ints[strtolower( $key )] ) {
							// make sure there are no blank spots in the insert syntax, yet try to avoid quotation marks around integers
							$value = ( null === $value || '' === $value ) ? $defs[strtolower( $key)] : $value;
							$values[] = ( '' === $value ) ? "''" : $value;
						} else {
							if (null === $value ) {
                                $values[] = 'NULL';
                            }
							else {
                                if ( is_serialized( $value ) && false !== ( $data = @unserialize( $value ) ) ) {
                                    if ( is_array( $data ) ) {
                                        array_walk_recursive( $data, 'bamboo_replace_array_values' );
                                    }
                                    elseif ( is_string( $data ) ) {
                                        $data = bamboo_apply_replaces( $data, true );
                                    }
                                    $value = serialize( $data );
                                } else {
                                    $value = bamboo_apply_replaces( $value );
                                }
                                $values[] = "'" . str_replace( $search, $replace, bamboo_sql_addslashes( $value ) ) . "'";
                            }
						}
					}

					bamboo_migrate_write_to_sql_file( $entries . implode(', ', $values) . ' ) ;' );

				}
				$row_start += $row_inc;
			}
		} while( ( count( $table_data ) > 0 ) );

		//Write the end of the sql
		bamboo_migrate_write_to_sql_file( "" );

		// Return success;
		return 'OK';

	}

/**********************************************************************************************************************/

	function bamboo_migrate_download_file() {

		// Clear execution time limit
        set_time_limit(0);

		// Establish the path to this plugin
		$path = plugin_dir_path( dirname( __FILE__ ) . '/bamboo-migrate.php' );

		// Establish the path to the sql file
		$bamboo_migrate_sql_path = $path . SQL_FILENAME;

		// If the file doesnt exist quit
		if( !file_exists( $bamboo_migrate_sql_path ) ) {
			wp_die( 'File "' . $bamboo_migrate_sql_path . '" not found.' );
		}

		// Generate a decent filename for the download
		$filename = home_url();
		$filename = str_replace('https://', '', $filename);
		$filename = str_replace('http://', '', $filename);
		$filename = str_replace('www.', '', $filename);
		$filename = str_replace('.', '-', $filename);

		// Send the file to the browser
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Length: ' . filesize( $bamboo_migrate_sql_path ) );
        header( 'Content-Disposition: attachment; filename=migrate-' . $filename . '.sql' );
        readfile( $bamboo_migrate_sql_path );

        // We're all done
        exit;

	}

/**********************************************************************************************************************/

	function bamboo_migrate_write_to_sql_file( $sql = '' ) {

		global $bamboo_migrate_sql_path;

		$sql_line = $sql . "\n";
		file_put_contents( $bamboo_migrate_sql_path, $sql_line, FILE_APPEND);

	}

/**********************************************************************************************************************/

	function bamboo_backquote( $table_name ) {

		if (!empty( $table_name ) && $table_name != '*' ) {
			if (is_array( $table_name ) ) {
				$result = array();
				reset( $table_name );
				while(list( $key, $val) = each( $table_name ) )
					$result[$key] = '`' . $val . '`';
				return $result;
			} else {
				return '`' . $table_name . '`';
			}
		} else {
			return $table_name;
		}

	}

/**********************************************************************************************************************/

	function bamboo_replace_array_values( &$value, $key ) {

        if ( !is_string( $value ) ) return;
        $value = bamboo_apply_replaces( $value, true );

	}

/**********************************************************************************************************************/

    function bamboo_apply_replaces( $subject, $is_serialized = false ) {

		global $bamboo_migrate_current_url, $bamboo_migrate_new_url;

        $search = array( $bamboo_migrate_current_url );
        $replace = array( $bamboo_migrate_new_url );
        $new = str_replace( $search, $replace, $subject );

        return $new;

    }

/**********************************************************************************************************************/

	function bamboo_sql_addslashes( $a_string = '', $is_like = false ) {

		if( $is_like ) {
			$a_string = str_replace( '\\', '\\\\\\\\', $a_string );
		} else {
			$a_string = str_replace( '\\', '\\\\', $a_string );
		}

		return str_replace( '\'', '\\\'', $a_string );

	}

/**********************************************************************************************************************/
?>