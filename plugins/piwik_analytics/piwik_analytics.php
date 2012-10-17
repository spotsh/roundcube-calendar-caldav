<?php

/**
 * 
 * piwik_analytics
 *
 * Bind piwik analytics script - based on: http://github.com/igloonet/roundcube_google_analytics
 *
 * @version 1.0 - 28. 11. 2010
 * @author Florian Beer
 * @modified_by Florian Beer
 * @website http://blog.no-panic.at
 * @licence GNU GPL
 *
 * Updated by Jeroen van Meeuwen (Kolab Systems) <vanmeeuwen@kolabsys.com>
 *
 **/

class piwik_analytics extends rcube_plugin
{
    function init()
    {
        if (file_exists(dirname(__FILE__) . "/config.inc.php")) {
            $this->load_config('config.inc.php');
        } elseif (file_exists(dirname(__FILE__) . "/config.inc.php.dist")) {
            $this->load_config('config.inc.php.dist');
        } elseif (file_exists(dirname(__FILE__) . "/config/config.inc.php")) {
            $this->load_config('config/config.inc.php');
        } elseif (file_exists(dirname(__FILE__) . "/config/config.inc.php.dist")) {
            $this->load_config('config/config.inc.php.dist');
        /* } else {
            error_log("Cannot find / load configuration for plugin piwik_analytics"); */
        }

        $this->add_hook('render_page', array($this, 'add_script'));
    }

    function add_script($args) {
        $rcmail = rcube::get_instance();

        $exclude = $rcmail->config->get('piwik_analytics_exclude');

        if (empty($exclude) || !is_array($exclude)) {
            $exclude = Array();
        }
    
        if (isset($exclude[$args['template']])) {
            return $args;
        }

        if ($rcmail->config->get('piwik_analytics_privacy', true)) {
            if (!empty($_SESSION['user_id'])) {
                return $args;
            }
        }
    
        if (!$rcmail->config->get('piwik_analytics_url', false)) {
            return $args;
        }

        $script = '
<!-- Piwik -->
<script type="text/javascript">
  var pkBaseURL = (("https:" == document.location.protocol) ? "https://' . $rcmail->config->get('piwik_analytics_url') . '" : "http://' . $rcmail->config->get('piwik_analytics_url') . '");
  document.write(unescape("%3Cscript src=\'" + pkBaseURL + "piwik.js\' type=\'text/javascript\'%3E%3C/script%3E"));
</script>
<script type="text/javascript">
  try {
    var piwikTracker = Piwik.getTracker(pkBaseURL + "piwik.php", ' . $rcmail->config->get('piwik_analytics_id') . ');
    piwikTracker.trackPageView();
    piwikTracker.enableLinkTracking();
  } catch( err ) {}
</script><noscript><p><img src="http://' . $rcmail->config->get('piwik_analytics_url') . '/piwik.php?idsite=' . $rcmail->config->get('piwik_analytics_id') . '" style="border:0" alt="" /></p></noscript>
<!-- End Piwik Tag -->';
    
        // add script to end of page
        $rcmail->output->add_footer($script);
     
        return $args;
    }
}

?>
