<?php
declare(strict_types=1);

namespace WPML_Mission;

/**
 * Plugin Name: WPML Auto-Duplication Mission Critical
 * Description: Safely duplicates Make.com posts to multiple WPML languages
 * Version: 3.7.1
 * Author: onwardSEO
 * 
 * PRODUCTION READY - WPML_MISSION_ENABLED is TRUE
 * Fixed: Initialization timing issue resolved
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================
// MISSION CONTROL PANEL - Guarded Definitions
// ============================================
if (!defined('WPML_MISSION_ENABLED')) {
    define('WPML_MISSION_ENABLED', true); // PRODUCTION MODE
}
if (!defined('EMERGENCY_STOP')) {
    define('EMERGENCY_STOP', false);
}
if (!defined('MAX_DUPLICATIONS_PER_DAY')) {
    define('MAX_DUPLICATIONS_PER_DAY', 50);
}
if (!defined('DUPLICATION_DELAY_SECONDS')) {
    define('DUPLICATION_DELAY_SECONDS', 45);
}
if (!defined('MAX_EXECUTION_TIME')) {
    define('MAX_EXECUTION_TIME', 120);
}
if (!defined('LOG_FILE_MAX_SIZE')) {
    define('LOG_FILE_MAX_SIZE', 5 * 1024 * 1024);
}
// --- optional: suppress the nginx warning banner -----------------
if ( ! defined( 'WPML_MISSION_SUPPRESS_NGINX_NOTICE' ) ) {
    define( 'WPML_MISSION_SUPPRESS_NGINX_NOTICE', true );
}
// -----------------------------------------------------------------
/**
 * WPML Mission Critical Duplicator
 */
class WPML_Mission_Critical_Duplicator {
    
    private array $target_languages;
    private string $log_file;
    private string $private_upload_dir;
    private bool $duplication_enabled;
    
    public function __construct(bool $duplication_enabled = true) {
        $this->duplication_enabled = $duplication_enabled;
        
        // Apply filterable configuration
        $this->target_languages = apply_filters('wpml_mission_target_langs', 
            array('en-gb', 'en-ca', 'en-au', 'en-us', 'en-nz')
        );
        
        // Set up private directory
        $private_suffix = get_option('wpml_mission_private_suffix');
        if (!$private_suffix) {
            $private_suffix = wp_generate_password(12, false);
            update_option('wpml_mission_private_suffix', $private_suffix);
        }
        
        $this->private_upload_dir = dirname(ABSPATH) . '/wpml-mission-private-' . $private_suffix;
        $this->log_file = $this->private_upload_dir . '/wpml-mission-log.txt';
        
        // CRITICAL FIX: Check if plugins_loaded already happened
        if (did_action('plugins_loaded')) {
            // We're past plugins_loaded, initialize immediately
            $this->initialize();
        } else {
            // Hook for later
            add_action('plugins_loaded', array($this, 'initialize'), 20);
        }
    }
    
    /**
     * Initialize after WPML is loaded
     */
    public function initialize(): void {
        // Debug confirmation
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WPML Mission: initialize() called successfully at ' . current_action());
        }
        
        // Check for multisite
        if (is_multisite()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>WPML Mission Critical Duplicator does not support multisite installations yet.</p></div>';
            });
            return;
        }
        
        if (EMERGENCY_STOP) {
            error_log('WPML Mission: EMERGENCY STOP ACTIVATED');
        }
        
        // Create private directory
        $this->ensure_private_directory();
        
        // System initialization  
        $this->run_diagnostics();
        
        // Hook duplication if enabled
        if ($this->duplication_enabled && WPML_MISSION_ENABLED && !EMERGENCY_STOP) {
            add_action('rest_after_insert_post', array($this, 'intercept_make_post'), 10, 3);
            add_action('wpml_mission_process', array($this, 'execute_duplication'));
        }
        
        // Always hook admin features
        add_action('admin_notices', array($this, 'mission_status_display'), 10);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wpml_mission_view_log', array($this, 'ajax_view_log'));
        add_action('admin_init', array($this, 'handle_admin_actions'));
        
        // Health monitoring
        add_action('wpml_mission_health_check', array($this, 'system_health_check'));
        if (!wp_next_scheduled('wpml_mission_health_check')) {
            wp_schedule_event(time(), 'hourly', 'wpml_mission_health_check');
        }
    }
    
    /**
     * MISSION CONTROL DISPLAY
     */
    public function mission_status_display(): void {
        if (!current_user_can('manage_options')) return;
        
        // Check if disabled due to directory failure
        if (!$this->duplication_enabled && WPML_MISSION_ENABLED) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>🚨 WPML MISSION CRITICAL - SYSTEM FAILURE</strong></p>';
            echo '<p>Cannot create log directory. Plugin functionality is disabled.</p>';
            echo '</div>';
            return;
        }
        
        $status = (WPML_MISSION_ENABLED && $this->duplication_enabled) ? 'ACTIVE' : 'STANDBY';
        $daily_count = $this->get_daily_count();
        $daily_limit = (int) apply_filters('wpml_mission_daily_limit', MAX_DUPLICATIONS_PER_DAY);
        $failure_count = (int) get_transient('wpml_mission_failure_count');
        $health = $failure_count > 0 ? 'WARNINGS' : 'NOMINAL';
        
        $class = (WPML_MISSION_ENABLED && $this->duplication_enabled) ? 'notice-success' : 'notice-warning';
        
        echo "<div class='notice $class'>";
        echo "<p><strong>🚀 WPML MISSION CONTROL</strong></p>";
        echo "<p>Status: <strong>$status</strong> | ";
        echo "Daily Operations: $daily_count/$daily_limit | ";
        echo "System Health: $health | ";
        echo "<a href='#' onclick='wpmlMissionViewLog(); return false;'>View Mission Log</a> | ";
        
        $abort_url = wp_nonce_url(
            admin_url('admin.php?wpml_mission_abort=1'),
            'wpml_mission_abort'
        );
        $reset_url = wp_nonce_url(
            admin_url('admin.php?wpml_mission_reset=1'),
            'wpml_mission_reset'
        );
        
        echo "<a href='$abort_url' onclick='return confirm(\"Abort all operations?\");'>Abort</a> | ";
        echo "<a href='$reset_url'>Reset</a>";
        
        if (EMERGENCY_STOP) {
            echo " | <strong style='color:red;'>EMERGENCY STOP ACTIVE</strong>";
        }
        
        echo "</p></div>";
        
        // Inline script for log viewing
        $nonce = wp_create_nonce('wpml_mission_log');
        ?>
        <script type="text/javascript">
        function wpmlMissionViewLog() {
            var win = window.open('', 'wpml_mission_log', 'width=800,height=600,scrollbars=yes');
            win.document.write('<html><head><title>WPML Mission Log</title></head><body><pre>Loading...</pre></body></html>');
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wpml_mission_view_log',
                    nonce: '<?php echo esc_js($nonce); ?>'
                },
                success: function(response) {
                    win.document.body.innerHTML = '<pre>' + response + '</pre>';
                },
                error: function() {
                    win.document.body.innerHTML = '<pre>Error loading log file.</pre>';
                }
            });
        }
        </script>
        <?php
    }
    
    /**
     * Ensure private directory exists
     */
    private function ensure_private_directory(): void {
        $created = false;
        $using_fallback = false;
        
        if (!file_exists($this->private_upload_dir)) {
            if (@wp_mkdir_p($this->private_upload_dir)) {
                $created = true;
                @chmod($this->private_upload_dir, 0750);
            } else {
                // Fallback to uploads
                $upload_dir = wp_upload_dir();
                $private_suffix = get_option('wpml_mission_private_suffix');
                $fallback_dir = trailingslashit($upload_dir['basedir']) . 'wpml-mission-secure-' . $private_suffix;
                
                $this->private_upload_dir = $fallback_dir;
                $this->log_file = $this->private_upload_dir . '/wpml-mission-log.txt';
                
                if (@wp_mkdir_p($this->private_upload_dir)) {
                    $created = true;
                    $using_fallback = true;
                    @chmod($this->private_upload_dir, 0750);
                    
                    // Create nginx deny configuration
                    $nginx_deny = $this->private_upload_dir . '/nginx-deny.conf';
                    if (!file_exists($nginx_deny)) {
                        $nginx_content = "location ~ ^" . str_replace(ABSPATH, '/', $this->private_upload_dir) . " {\n";
                        $nginx_content .= "    deny all;\n";
                        $nginx_content .= "    return 403;\n";
                        $nginx_content .= "}\n";
                        file_put_contents($nginx_deny, $nginx_content);
                    }
                } else {
                    // Critical failure
                    add_action('admin_notices', function() {
                        echo '<div class="notice notice-error"><p><strong>WPML Mission Critical ERROR:</strong> ';
                        echo 'Cannot create log directory. Please check file system permissions.</p></div>';
                    });
                    
                    $this->duplication_enabled = false;
                    return;
                }
            }
        } else {
            $created = true;
            $using_fallback = (strpos($this->private_upload_dir, 'uploads') !== false);
        }
        
        // Add Nginx warning if needed
        // Add Nginx warning if needed
if ( $using_fallback
     && isset( $_SERVER['SERVER_SOFTWARE'] )
     && stripos( $_SERVER['SERVER_SOFTWARE'], 'nginx' ) !== false
     && ! defined( 'WPML_MISSION_SUPPRESS_NGINX_NOTICE' ) ) {   // ← added check
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-warning"><p><strong>WPML Mission - Nginx Security Notice:</strong> ';
        echo 'Please add the deny rule from <code>' . esc_html( $this->private_upload_dir ) . '/nginx-deny.conf</code> ';
        echo 'to your Nginx configuration.</p></div>';
    } );
}

        
        if (!$created) {
            return;
        }
        
        // Add .htaccess
        $htaccess = $this->private_upload_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $htaccess_content = "# WPML Mission Critical - Deny All Access\n\n";
            $htaccess_content .= "<IfModule mod_authz_core.c>\n";
            $htaccess_content .= "    Require all denied\n";
            $htaccess_content .= "</IfModule>\n\n";
            $htaccess_content .= "<IfModule !mod_authz_core.c>\n";
            $htaccess_content .= "    Order deny,allow\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "</IfModule>\n";
            file_put_contents($htaccess, $htaccess_content);
        }
        
        // Add index.php
        $index = $this->private_upload_dir . '/index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden\ndie('Access denied');\n");
        }
    }
    
    /**
     * Run system diagnostics
     */
    private function run_diagnostics(): void {
        $using_fallback = (strpos($this->private_upload_dir, 'uploads') !== false);
        
        $diagnostics = array(
            'wordpress_version' => get_bloginfo('version'),
            'wpml_version' => defined('ICL_SITEPRESS_VERSION') ? ICL_SITEPRESS_VERSION : 'not detected',
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'active_languages' => count(apply_filters('wpml_active_languages', array())),
            'is_multisite' => is_multisite(),
            'duplication_enabled' => $this->duplication_enabled && WPML_MISSION_ENABLED,
            'log_directory' => $this->private_upload_dir,
            'log_outside_webroot' => !$using_fallback,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
        );
        
        $this->mission_log('SYSTEM DIAGNOSTICS', json_encode($diagnostics, JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * Intercept Make.com posts
     */
    public function intercept_make_post($post, $request, $creating): void {
        // Check WPML when we actually need it
        if (!defined('ICL_SITEPRESS_VERSION')) {
            $this->mission_log('ERROR', 'WPML not active - cannot process duplication');
            return;
        }
        
        if (!$creating || $post->post_type !== 'post') {
            return;
        }
        
        if (!$this->verify_make_request()) {
            return;
        }
        
        // Check daily limit
        if (!$this->check_daily_limit()) {
            $this->mission_log('ABORT', "Daily limit reached for post {$post->ID}");
            return;
        }
        
        // Check circuit breaker
        if (get_transient('wpml_mission_circuit_breaker')) {
            $this->mission_log('ABORT', "Circuit breaker active");
            return;
        }
        
        // Check if already processed
        $status = get_post_meta($post->ID, '_wpml_mission_status', true);
        if ($status) {
            $this->mission_log('SKIP', "Post {$post->ID} already processed: $status");
            return;
        }
        
        // Check if this is already a duplicate
        $source_language = apply_filters('wpml_element_source_language_code', null, array(
            'element_id' => $post->ID,
            'element_type' => 'post'
        ));
        
        if ($source_language !== null) {
            $this->mission_log('SKIP', "Post {$post->ID} is already a translation");
            return;
        }
        
        // Collect mission data
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $hashed_ip = ($ip !== 'unknown') ? hash_hmac('sha256', $ip, AUTH_SALT) : 'unknown';
        
        $mission_data = array(
            'post_id' => $post->ID,
            'title' => $post->post_title,
            'language' => apply_filters('wpml_current_language', 'en'),
            'timestamp' => current_time('mysql'),
            'request_ip_hash' => $hashed_ip
        );
        
        update_post_meta($post->ID, '_wpml_mission_data', $mission_data);
        update_post_meta($post->ID, '_wpml_mission_status', 'scheduling');
        
        // Schedule mission
        $scheduled = wp_schedule_single_event(
            time() + DUPLICATION_DELAY_SECONDS,
            'wpml_mission_process',
            array($post->ID)
        );
        
        if ($scheduled !== false) {
            update_post_meta($post->ID, '_wpml_mission_status', 'scheduled');
            $this->mission_log('SCHEDULED', "Post {$post->ID} scheduled for duplication");
            
            // Increment counter
            $this->increment_daily_counter_after_success();
            
            // Post-increment validation
            $current_total = $this->get_daily_count();
            $max_limit = (int) apply_filters('wpml_mission_daily_limit', MAX_DUPLICATIONS_PER_DAY);
            
            if ($current_total > $max_limit) {
                // Race condition - roll back
                $this->decrement_daily_counter();
                
                $scheduled_timestamp = wp_next_scheduled('wpml_mission_process', array($post->ID));
                if ($scheduled_timestamp) {
                    wp_unschedule_event($scheduled_timestamp, 'wpml_mission_process', array($post->ID));
                }
                
                update_post_meta($post->ID, '_wpml_mission_status', 'quota_exceeded');
                $this->mission_log('ABORT', "Quota overrun detected - job unscheduled for post {$post->ID}");
                return;
            }
        } else {
            $this->mission_log('ERROR', "Failed to schedule post {$post->ID}");
            update_post_meta($post->ID, '_wpml_mission_status', 'schedule_failed');
        }
    }
    
    /**
     * Execute duplication
     */
    public function execute_duplication(int $post_id): void {
        // Check circuit breaker
        if (get_transient('wpml_mission_circuit_breaker')) {
            $this->mission_log('ABORT', "Circuit breaker active");
            wp_schedule_single_event(time() + 300, 'wpml_mission_process', array($post_id));
            return;
        }
        
        // Set circuit breaker
        $breaker_timeout = max(MAX_EXECUTION_TIME * 2, 900);
        set_transient('wpml_mission_circuit_breaker', array(
            'post_id' => $post_id,
            'started' => time()
        ), $breaker_timeout);
        
        if (function_exists('set_time_limit')) {
            @set_time_limit(MAX_EXECUTION_TIME);
        }
        
        try {
            $post = get_post($post_id);
            if (!$post) {
                throw new \Exception("Post $post_id no longer exists");
            }
            
            // Get source language
            $source_lang = apply_filters('wpml_element_language_code', '', array(
                'element_id' => $post_id,
                'element_type' => 'post'
            ));
            
            if (!$source_lang) {
                $source_lang = 'en-gb';
            }
            
            $this->mission_log('EXECUTE', "Starting duplication for post $post_id (lang: $source_lang)");
            
            // Check existing translations
            $existing_translations = $this->get_existing_translations($post_id);
            $languages_to_process = array_diff($this->target_languages, $existing_translations, array($source_lang));
            
            if (empty($languages_to_process)) {
                $this->mission_log('INFO', "Post $post_id already has all translations");
                update_post_meta($post_id, '_wpml_mission_status', 'already_complete');
                return;
            }
            
            // Process each language
            $success_count = 0;
            $results = array();
            
            foreach ($languages_to_process as $target_lang) {
                if (get_option('wpml_mission_abort', false)) {
                    throw new \Exception("Mission abort signal received");
                }
                
                // Create duplicate
                global $sitepress;
                $new_id = false;
                
                if (is_callable(array($sitepress, 'make_duplicate'))) {
                    $new_id = $sitepress->make_duplicate($post_id, $target_lang);
                } else {
                    $this->mission_log('WARN', "Duplication for {$target_lang} skipped - make_duplicate API unavailable");
                    continue;
                }
                
                if ($new_id && is_numeric($new_id) && $new_id !== $post_id) {
                    // Clean up meta
                    delete_post_meta($new_id, '_wpml_mission_status');
                    delete_post_meta($new_id, '_wpml_mission_data');
                    delete_post_meta($new_id, '_wpml_mission_results');
                    
                    $success_count++;
                    $results[$target_lang] = $new_id;
                    $this->mission_log('SUCCESS', "Created $target_lang version: ID $new_id");
                } else {
                    $this->mission_log('WARN', "Failed to create {$target_lang} version");
                }
                
                // Sleep between operations
                if ($success_count < count($languages_to_process)) {
                    if (function_exists('wp_sleep')) {
                        wp_sleep(3);
                    } else {
                        sleep(3);
                    }
                }
            }
            
            // Mission complete
            update_post_meta($post_id, '_wpml_mission_status', 'completed');
            update_post_meta($post_id, '_wpml_mission_results', $results);
            update_post_meta($post_id, '_wpml_mission_completed', current_time('mysql'));
            
            $this->mission_log('COMPLETE', "Post $post_id: $success_count languages created");
            
        } catch (\Exception $e) {
            $this->mission_log('CRITICAL', $e->getMessage());
            update_post_meta($post_id, '_wpml_mission_status', 'failed');
            update_post_meta($post_id, '_wpml_mission_error', $e->getMessage());
            
            // Decrement counter
            $this->decrement_daily_counter();
            
            // Set failure timestamp
            set_transient('wpml_mission_last_failure', time(), 86400);
            
            // Reschedule if appropriate
            $failure_count = (int) get_transient('wpml_mission_failure_count');
            if ($failure_count < 3) {
                set_transient('wpml_mission_failure_count', $failure_count + 1, 3600);
                wp_schedule_single_event(time() + 300, 'wpml_mission_process', array($post_id));
                $this->mission_log('RETRY', "Rescheduled post $post_id for retry");
            }
            
        } finally {
            // Release circuit breaker
            delete_transient('wpml_mission_circuit_breaker');
        }
    }
    
    /**
     * Helper methods
     */
    private function verify_make_request(): bool {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $headers = array();
        if (function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        } else {
            foreach ($_SERVER as $key => $value) {
                if (substr($key, 0, 5) === 'HTTP_') {
                    $header = str_replace('_', '-', strtolower(substr($key, 5)));
                    $headers[$header] = $value;
                }
            }
        }
        
        return (
            strpos($user_agent, 'Make') !== false ||
            strpos($user_agent, 'Integromat') !== false ||
            isset($headers['x-make-scenario-id']) ||
            isset($headers['x-integromat-scenario-id'])
        );
    }
    
    private function get_existing_translations(int $post_id): array {
        $translations = apply_filters('wpml_get_element_translations', array(), $post_id, 'post');
        return array_keys($translations);
    }
    
    private function check_daily_limit(): bool {
        $count_key = 'wpml_mission_daily_count_' . date('Y-m-d');
        $current_count = (int) get_option($count_key, 0);
        $max_limit = (int) apply_filters('wpml_mission_daily_limit', MAX_DUPLICATIONS_PER_DAY);
        return $current_count < $max_limit;
    }
    
    private function increment_daily_counter_after_success(): void {
        global $wpdb;
        
        $count_key = 'wpml_mission_daily_count_' . date('Y-m-d');
        
        add_option($count_key, 0, '', 'no');
        
        $max_retries = 10;
        $retry_count = 0;
        
        do {
            $rows = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} 
                 SET option_value = CAST(option_value AS UNSIGNED) + 1 
                 WHERE option_name = %s",
                $count_key
            ));
            
            if ($rows > 0) {
                break;
            }
            
            $retry_count++;
            if ($retry_count >= $max_retries) {
                $this->mission_log('ERROR', 'Failed to increment daily counter');
                break;
            }
            
            usleep(50000);
        } while (true);
        
        // Clean old counts
        $yesterday = 'wpml_mission_daily_count_' . date('Y-m-d', strtotime('-2 days'));
        delete_option($yesterday);
    }
    
    private function decrement_daily_counter(): void {
        global $wpdb;
        
        $count_key = 'wpml_mission_daily_count_' . date('Y-m-d');
        
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} 
             SET option_value = GREATEST(CAST(option_value AS SIGNED) - 1, 0)
             WHERE option_name = %s",
            $count_key
        ));
    }
    
    private function get_daily_count(): int {
        $count_key = 'wpml_mission_daily_count_' . date('Y-m-d');
        return (int) get_option($count_key, 0);
    }
    
    public function system_health_check(): void {
        // Check for stuck operations
        $stuck_posts = get_posts(array(
            'post_type' => 'post',
            'meta_key' => '_wpml_mission_status',
            'meta_value' => 'scheduled',
            'meta_compare' => '=',
            'date_query' => array(
                array(
                    'before' => '2 hours ago'
                )
            ),
            'posts_per_page' => -1
        ));
        
        if (!empty($stuck_posts)) {
            foreach ($stuck_posts as $stuck_post) {
                update_post_meta($stuck_post->ID, '_wpml_mission_status', 'timeout');
                $this->mission_log('HEALTH', "Marked post {$stuck_post->ID} as timeout");
            }
        }
        
        // Reset failure counter if old
        $last_failure = get_transient('wpml_mission_last_failure');
        if ($last_failure && (time() - $last_failure) > 3600) {
            delete_transient('wpml_mission_failure_count');
            delete_transient('wpml_mission_last_failure');
            $this->mission_log('HEALTH', 'Failure counter reset');
        }
        
        // Rotate log
        $this->rotate_log_if_needed();
    }
    
    private function rotate_log_if_needed(): void {
        if (!file_exists($this->log_file)) {
            return;
        }
        
        $size = filesize($this->log_file);
        if ($size > LOG_FILE_MAX_SIZE) {
            $backup = $this->log_file . '.' . date('Y-m-d-His');
            rename($this->log_file, $backup);
            @chmod($backup, 0640);
            
            $this->mission_log('MAINTENANCE', 'Log rotated to: ' . basename($backup));
            
            // Keep only last 5 backups
            $backups = glob($this->private_upload_dir . '/wpml-mission-log.txt.*');
            if (count($backups) > 5) {
                usort($backups, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                $to_delete = array_slice($backups, 0, count($backups) - 5);
                foreach ($to_delete as $old_backup) {
                    unlink($old_backup);
                }
            }
        }
    }
    
    private function mission_log(string $type, string $message): void {
        if (!is_dir($this->private_upload_dir)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("WPML MISSION [$type]: $message (log directory unavailable)");
            }
            return;
        }
        
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$type] $message\n";
        
        $file_exists = file_exists($this->log_file);
        @file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        if (!$file_exists && file_exists($this->log_file)) {
            @chmod($this->log_file, 0640);
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("WPML MISSION [$type]: $message");
        }
    }
    
    public function enqueue_admin_scripts(): void {
        if (current_user_can('manage_options')) {
            wp_enqueue_script('jquery');
        }
    }
    
    public function ajax_view_log(): void {
        check_ajax_referer('wpml_mission_log', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        
        if (!is_dir($this->private_upload_dir)) {
            echo "Log directory does not exist.";
            wp_die();
        }
        
        if (file_exists($this->log_file)) {
            $log_content = file_get_contents($this->log_file);
            echo esc_html($log_content);
        } else {
            echo "No log file found.";
        }
        
        wp_die();
    }
    
    public function handle_admin_actions(): void {
        if (isset($_GET['wpml_mission_abort']) && current_user_can('manage_options')) {
            check_admin_referer('wpml_mission_abort');
            update_option('wpml_mission_abort', true);
            wp_die('🛑 MISSION ABORT SIGNAL SENT - All operations will cease.');
        }
        
        if (isset($_GET['wpml_mission_reset']) && current_user_can('manage_options')) {
            check_admin_referer('wpml_mission_reset');
            delete_option('wpml_mission_abort');
            delete_transient('wpml_mission_circuit_breaker');
            delete_transient('wpml_mission_failure_count');
            delete_transient('wpml_mission_last_failure');
            wp_die('✅ MISSION SYSTEMS RESET - Ready for operations.');
        }
    }
}

// PLUGIN INITIALIZATION
add_action('init', __NAMESPACE__ . '\wpml_mission_critical_init');

function wpml_mission_critical_init(): void {
    static $initialized = false;
    
    if ($initialized) {
        return;
    }
    $initialized = true;
    
    if (!EMERGENCY_STOP) {
        $instance = new WPML_Mission_Critical_Duplicator(WPML_MISSION_ENABLED);
        $GLOBALS['wpml_mission_instance'] = $instance;
    } else {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>🚨 WPML Mission Critical - EMERGENCY STOP ACTIVE</strong></p></div>';
        });
    }
}

// Plugin activation hook
register_activation_hook(__FILE__, __NAMESPACE__ . '\wpml_mission_activation');
function wpml_mission_activation() {
    // WPML check happens at runtime
}

// Plugin deactivation cleanup
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\wpml_mission_deactivation');
function wpml_mission_deactivation() {
    wp_clear_scheduled_hook('wpml_mission_health_check');
    delete_transient('wpml_mission_circuit_breaker');
    delete_transient('wpml_mission_failure_count');
    delete_transient('wpml_mission_last_failure');
    delete_option('wpml_mission_abort');
}

// CLI Command Support
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('wpml-mission', __NAMESPACE__ . '\WPML_Mission_CLI');
}

/**
 * WP-CLI Command Handler
 */
class WPML_Mission_CLI {
    public function duplicate($args, $assoc_args) {
        $post_id = (int) $args[0];
        
        if (!get_post($post_id)) {
            \WP_CLI::error("Post ID $post_id does not exist");
        }
        
        if (!empty($assoc_args['langs'])) {
            $langs = array_map('trim', explode(',', $assoc_args['langs']));
            add_filter('wpml_mission_target_langs', function() use ($langs) {
                return $langs;
            });
            \WP_CLI::log("Using languages: " . implode(', ', $langs));
        }
        
        $duplicator = new WPML_Mission_Critical_Duplicator(true);
        \WP_CLI::log("Starting manual duplication for post $post_id");
        \WP_CLI::log("Note: CLI duplication bypasses daily quota limits");
        
        $duplicator->execute_duplication($post_id);
        
        \WP_CLI::success("Duplication process completed for post $post_id");
    }
}