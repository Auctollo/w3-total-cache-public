<?php
namespace W3TC;

/**
 * class Cdn_Environment
 */
class Cdn_Environment {

	/**
	 * Fixes environment in each wp-admin request
	 *
	 * @param Config  $config
	 * @param bool    $force_all_checks
	 * @throws Util_Environment_Exceptions
	 */
	public function fix_on_wpadmin_request( $config, $force_all_checks ) {
		$exs = new Util_Environment_Exceptions();

		if ( $config->get_boolean( 'config.check' ) || $force_all_checks ) {
			if ( $config->get_boolean( 'cdn.enabled' ) ) {
				$this->rules_add( $config, $exs );
			} else {
				$this->rules_remove( $exs );
			}
		}

		if ( count( $exs->exceptions() ) > 0 )
			throw $exs;
	}

	/**
	 * Fixes environment once event occurs
	 *
	 * @param Config  $config
	 * @param string  $event
	 * @param Config|null $old_config
	 * @throws Util_Environment_Exceptions
	 */
	public function fix_on_event( $config, $event, $old_config = null ) {
		if ( $config->get_boolean( 'cdn.enabled' ) &&
			!Cdn_Util::is_engine_mirror( $config->get_string( 'cdn.engine' ) ) ) {
			if ( $old_config != null &&
				$config->get_integer( 'cdn.queue.interval' ) !=
				$old_config->get_integer( 'cdn.queue.interval' ) ) {
				$this->unschedule_queue_process();
			}

			if ( !wp_next_scheduled( 'w3_cdn_cron_queue_process' ) ) {
				wp_schedule_event( time(),
					'w3_cdn_cron_queue_process', 'w3_cdn_cron_queue_process' );
			}
		} else {
			$this->unschedule_queue_process();
		}

		if ( $config->get_boolean( 'cdn.enabled' ) &&
			$config->get_boolean( 'cdn.autoupload.enabled' ) &&
			!Cdn_Util::is_engine_mirror( $config->get_string( 'cdn.engine' ) ) ) {
			if ( $old_config != null &&
				$config->get_integer( 'cdn.autoupload.interval' ) !=
				$old_config->get_integer( 'cdn.autoupload.interval' ) ) {
				$this->unschedule_upload();
			}

			if ( !wp_next_scheduled( 'w3_cdn_cron_upload' ) ) {
				wp_schedule_event( time(),
					'w3_cdn_cron_upload', 'w3_cdn_cron_upload' );
			}
		} else {
			$this->unschedule_upload();
		}

		$exs = new Util_Environment_Exceptions();

		if ( $config->get_boolean( 'cdn.enabled' ) ) {
			try {
				$this->handle_tables(
					$event == 'activate' /* drop state on activation */,
					true );
			} catch ( \Exception $ex ) {
				$exs->push( $ex );
			}
		}

		if ( count( $exs->exceptions() ) > 0 )
			throw $exs;
	}

	/**
	 * Fixes environment after plugin deactivation
	 */
	public function fix_after_deactivation() {
		$exs = new Util_Environment_Exceptions();

		$this->rules_remove( $exs );
		$this->handle_tables( true, false );

		if ( count( $exs->exceptions() ) > 0 )
			throw $exs;
	}

	/**
	 * Returns required rules for module
	 *
	 * @param Config  $config
	 * @return array|null
	 */
	function get_required_rules( $config ) {
		if ( !$config->get_boolean( 'cdn.enabled' ) )
			return null;

		$rewrite_rules = array();
		$rules = $this->rules_generate( $config );

		if ( strlen( $rules ) > 0 ) {
			if ( $config->get_string( 'cdn.engine' ) == 'ftp' ) {
				$common = Dispatcher::component( 'Cdn_Core' );
				$domain = $common->get_cdn()->get_domain();
				$cdn_rules_path = sprintf( 'ftp://%s/%s', $domain,
					Util_Rule::get_cdn_rules_path() );
				$rewrite_rules[] = array(
					'filename' => $cdn_rules_path,
					'content' => $rules
				);
			}

			$path = Util_Rule::get_browsercache_rules_cache_path();
			$rewrite_rules[] = array(
				'filename' => $path,
				'content' => $rules
			);
		}
		return $rewrite_rules;
	}

	/**
	 *
	 *
	 * @param Config  $config
	 * @return array|null
	 */
	function get_instructions( $config ) {
		if ( !$config->get_boolean( 'cdn.enabled' ) )
			return null;

		$instructions = array();
		$instructions[] = array( 'title'=>__( 'CDN module: Required Database SQL', 'w3-total-cache' ),
			'content' => $this->generate_table_sql(), 'area' => 'database' );

		return $instructions;
	}

	/**
	 * Generate rules for FTP
	 */
	public function rules_generate_for_ftp( $config ) {
		return $this->rules_generate( $config, true );
	}



	/**
	 * Create tables
	 *
	 * @param bool    $drop
	 * @throws Util_Environment_Exception
	 */
	private function handle_tables( $drop, $create ) {
		global $wpdb;

		$tablename_queue = $wpdb->base_prefix . W3TC_CDN_TABLE_QUEUE;
		$tablename_map = $wpdb->base_prefix . W3TC_CDN_TABLE_PATHMAP;

		if ( $drop ) {
			$sql = "DROP TABLE IF EXISTS `$tablename_queue`;";
			$wpdb->query( $sql );
			$sql = "DROP TABLE IF EXISTS `$tablename_map`;";
			$wpdb->query( $sql );
		}

		if ( !$create ) {
			return;
		}

		$charset_collate = '';
		if ( ! empty( $wpdb->charset ) )
			$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
		if ( ! empty( $wpdb->collate ) )
			$charset_collate .= " COLLATE $wpdb->collate";

		$sql = "CREATE TABLE IF NOT EXISTS `$tablename_queue` (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`local_path` varchar(500) NOT NULL DEFAULT '',
			`remote_path` varchar(500) NOT NULL DEFAULT '',
			`command` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '1 - Upload, 2 - Delete, 3 - Purge',
			`last_error` varchar(150) NOT NULL DEFAULT '',
			`date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (`id`),
			KEY `date` (`date`)
		) $charset_collate;";

		$wpdb->query( $sql );
		if ( !$wpdb->result )
			throw new Util_Environment_Exception( 'Can\'t create table ' .
				$tablename_queue );

		$sql = "
			CREATE TABLE IF NOT EXISTS `$tablename_map` (
				-- Relative file path.
				-- For reference, not actually used for finding files.
				path TEXT NOT NULL,
				-- MD5 hash of remote path, used for finding files.
				path_hash VARCHAR(32) CHARACTER SET ascii NOT NULL,
				type tinyint(1) NOT NULL DEFAULT '0',
				-- Google Drive: document identifier
				remote_id VARCHAR(200) CHARACTER SET ascii,
				PRIMARY KEY (path_hash),
				KEY `remote_id` (`remote_id`)
			) $charset_collate";

		$wpdb->query( $sql );
		if ( !$wpdb->result )
			throw new Util_Environment_Exception( 'Can\'t create table ' .
				$tablename_map );
	}



	private function generate_table_sql() {
		global $wpdb;
		$charset_collate = '';

		if ( ! empty( $wpdb->charset ) )
			$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
		if ( ! empty( $wpdb->collate ) )
			$charset_collate .= " COLLATE $wpdb->collate";

		$sql = sprintf( 'DROP TABLE IF EXISTS `%s%s`;', $wpdb->base_prefix, W3TC_CDN_TABLE_QUEUE );
		$sql .= "\n" . sprintf( "CREATE TABLE IF NOT EXISTS `%s%s` (
			`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
			`local_path` varchar(500) NOT NULL DEFAULT '',
			`remote_path` varchar(500) NOT NULL DEFAULT '',
			`command` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '1 - Upload, 2 - Delete, 3 - Purge',
			`last_error` varchar(150) NOT NULL DEFAULT '',
			`date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (`id`),
			KEY `date` (`date`)
		) $charset_collate;", $wpdb->base_prefix, W3TC_CDN_TABLE_QUEUE );

		return $sql;
	}

	/**
	 * schedules
	 */

	/**
	 * Unschedules cron events
	 */
	private function unschedule_queue_process() {
		if ( wp_next_scheduled( 'w3_cdn_cron_queue_process' ) ) {
			wp_clear_scheduled_hook( 'w3_cdn_cron_queue_process' );
		}
	}

	/**
	 * Unschedule upload event
	 */
	private function unschedule_upload() {
		if ( wp_next_scheduled( 'w3_cdn_cron_upload' ) ) {
			wp_clear_scheduled_hook( 'w3_cdn_cron_upload' );
		}
	}



	/*
	 * rules core modification
	 */

	/**
	 * Writes directives to WP .htaccess
	 *
	 * @param Config  $config
	 * @param Util_Environment_Exceptions $exs
	 * @throws Util_WpFile_FilesystemOperationException with S/FTP form if it can't get the required filesystem credentials
	 */
	private function rules_add( $config, $exs ) {
		Util_Rule::add_rules( $exs, Util_Rule::get_browsercache_rules_cache_path(),
			$this->rules_generate( $config ),
			W3TC_MARKER_BEGIN_CDN,
			W3TC_MARKER_END_CDN,
			array(
				W3TC_MARKER_BEGIN_MINIFY_CORE => 0,
				W3TC_MARKER_BEGIN_PGCACHE_CORE => 0,
				W3TC_MARKER_BEGIN_BROWSERCACHE_NO404WP => 0,
				W3TC_MARKER_BEGIN_BROWSERCACHE_CACHE => 0,
				W3TC_MARKER_BEGIN_WORDPRESS => 0,
				W3TC_MARKER_END_PGCACHE_CACHE => strlen( W3TC_MARKER_END_PGCACHE_CACHE ) + 1,
				W3TC_MARKER_END_MINIFY_CACHE => strlen( W3TC_MARKER_END_MINIFY_CACHE ) + 1
			)
		);
	}

	/**
	 * Removes Page Cache core directives
	 *
	 * @param Util_Environment_Exceptions $exs
	 * @throws Util_WpFile_FilesystemOperationException with S/FTP form if it can't get the required filesystem credentials
	 */
	private function rules_remove( $exs ) {
		Util_Rule::remove_rules( $exs,
			Util_Rule::get_browsercache_rules_cache_path(),
			W3TC_MARKER_BEGIN_CDN,
			W3TC_MARKER_END_CDN );
	}

	/**
	 * Generates rules for WP dir
	 *
	 * @param Config  $config
	 * @param bool    $cdnftp
	 * @return string
	 */
	private function rules_generate( $config, $cdnftp = false ) {
		if ( Util_Environment::is_nginx() ) {
			return Cdn_Environment_Nginx::generate( $config, $cdnftp );
		} else {
			return $this->rules_generate_apache( $config, $cdnftp );
		}
	}

	private function rules_generate_apache( $config, $cdnftp ) {
		$rules = '';
		if ( $config->get_boolean( 'cdn.canonical_header' ) ) {
			$rules .= Util_RuleSnippet::canonical( $cdnftp,
				$config->get_boolean( 'cdn.cors_header') );
		}

		if ( $config->get_boolean( 'cdn.cors_header') ) {
			$rules .= $this->allow_origin( $cdnftp );
		}

		if ( strlen( $rules ) > 0 ) {
			$rules =
				W3TC_MARKER_BEGIN_CDN . "\n" .
				$rules .
				W3TC_MARKER_END_CDN . "\n";
		}

		return $rules;
	}

	/**
	 * Returns allow-origin rules
	 */
	private function allow_origin( $cdnftp = false ) {
		switch ( true ) {
		case Util_Environment::is_apache():
		case Util_Environment::is_litespeed():
			$r  = "<IfModule mod_headers.c>\n";
			$r .= "    Header set Access-Control-Allow-Origin \"*\"\n";
			$r .= "</IfModule>\n";

			if ( !$cdnftp )
				return $r;
			else
				return
				"<FilesMatch \"\.(ttf|ttc|otf|eot|woff|woff2|font.css)$\">\n" .
					$r .
					"</FilesMatch>\n";
		}

		return '';
	}
}
