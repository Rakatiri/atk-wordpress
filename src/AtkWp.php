<?php
/* =====================================================================
 * atk-wordpress => Wordpress interface for Agile Toolkit Framework.
 *
 * This interface enable the use of the Agile Toolkit framework within a WordPress site.
 *
 * Please note that when atk is mentioned it generally refer to Agile Toolkit.
 * More information on Agile Toolkit: http://www.agiletoolkit.org
 *
 * Author: Alain Belair
 * Licensed under MIT
 * =====================================================================*/
/**
 * The actual plugin class implementation for WP.
 */

namespace atkwp;

use atk4\data\Persistence_SQL;
use atk4\ui\Exception;
use atk4\ui\Text;
use atk4\ui\View;
use atkwp\helpers\Config;
use atkwp\interfaces\ComponentCtrlInterface;
use atkwp\interfaces\PathInterface;

class AtkWp
{
    //The  name of the plugin
    public $pluginName;

    //The plugin component controller
    public $componentCtrl;

    protected $isExecuting;

    //Whether initialized_layout is bypass or not.
    public $isLayoutNeedInitialise = true;

    //wp default layout template.
    public $defaultLayout = 'layout.html';

    //the current wp view to output. ( Ex: admin panel, shortcode or metabox)
    public $wpComponent;

    /*
     * keep track of how many components are output.
     * Mainly use for shortcode component.
     */
    public $componentCount = 0;

    //the database connection for this plugin.
    public $dbConnection;

    //plugin path locator for template file.
    public $pathFinder;

    //plugin configuration
    public $config;

    /**
     * AtkWp constructor.
     *
     * @param string                 $pluginName The name of this plugin.
     * @param PathInterface          $pathFinder The pathFinder object for retrieving atk template file under WP.
     * @param ComponentCtrlInterface $ctrl       The ctrl object responsible to initialize all WP components.
     */
    public function __construct($pluginName, PathInterface $pathFinder, ComponentCtrlInterface $ctrl)
    {
        $this->pluginName = $pluginName;
        $this->pathFinder = $pathFinder;
        $this->componentCtrl = $ctrl;
        $this->config = new Config($this->pathFinder->getConfigurationPath());
        $this->init();
    }

    public function getPluginName()
    {
        return $this->pluginName;
    }

    public function getConfig($name, $default = null)
    {
        return $this->config->getConfig($name, $default);
    }

    public function setConfig($config = [], $defautl = UNDEFINED)
    {
        $this->config->setConfig($config, $defautl);
    }

    public function getTemplateLocation($fileName)
    {
        return $this->pathFinder->getTemplateLocation($fileName);
    }

    public function getDbConnection()
    {
        return $this->dbConnection;
    }

    public function setDbConnection()
    {
        $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME;
        $this->dbConnection = new Persistence_SQL($dsn, DB_USER, DB_PASSWORD);
    }

    public function getWpComponentId()
    {
        return $this->wpComponent['id'];
    }

    public function getComponentCount()
    {
        return $this->componentCount;
    }

    /**
     * Plugin Entry point
     * Wordpress plugin file call this function in order to initialise
     * atk to work under Wordpress.
     *
     * @param string $filePath the path to this WP plugin file.
     */
    public function boot($filePath)
    {
        //setup plugin activation / deactivation hook.
        register_activation_hook($filePath, [$this, 'activatePlugin']);
        register_deactivation_hook($filePath, [$this, 'deactivatePlugin']);

        //setup component services.
        $this->componentCtrl->initializeComponents($this);

        //register ajax action for this plugin
        add_action("wp_ajax_{$this->getPluginName()}", [$this, 'wpAjaxExecute']);
        if ($this->config->getConfig('plugin/use_ajax_front')) {
            //$this->sticky_get_arguments['_ajax_nonce'] = wp_create_nonce($this->pluginName);
            //enable Wp ajax front end action.
            add_action("wp_ajax_nopriv_{$this->getPluginName()}", [$this, 'wpAjaxExecute']);
        }
    }

    /**
     * Plugin Initialize function.
     */
    public function init()
    {
    }

    /**
     * Create a new AtkWpApp View.
     * This view is fully initialize with an atk application.
     * WidgetComponent use this to create a view for Widget.
     *  - You can output (echo) this view using $view->app->execute().
     *
     * @param string $template the template to use with this view.
     * @param string $name     the name of the application.
     *
     * @return \atk4\ui\View
     */
    public function newAtkAppView($template, $name)
    {
        $app = new AtkWpApp($this);

        return $app->initWpLayout(new View(), $template, $name);
    }

    /**
     * Catch exception.
     *
     * @param $exception
     *
     * @throws Exception
     */
    public function caughtException($exception)
    {
        $view = $this->newAtkAppView('layout.html', $this->pluginName);
        if ($exception instanceof \atk4\core\Exception) {
            $view->template->setHTML('Content', $exception->getHTML());
        } elseif ($exception instanceof \Error) {
            $view->add(new View([
                'ui'=> 'message',
                get_class($exception).': '.$exception->getMessage().' (in '.$exception->getFile().':'.$exception->getLine().')',
                'error',
                ])
            );
            $view->add(new Text())->set(nl2br($exception->getTraceAsString()));
        } else {
            $view->add(new View(['ui'=>'message', get_class($exception).': '.$exception->getMessage(), 'error']));
        }
        $view->template->tryDel('Header');
        $view->app->execute();
    }

    /*--------------------- PLUGIN OUTPUTS -------------------------------*/

    /**
     * Output Panel view in Wp.
     */
    public function wpAdminExecute()
    {
        global $hook_suffix;
        $this->wpComponent = $this->componentCtrl->searchComponentByType('panel', $hook_suffix, 'hook');

        try {
            $view = new $this->wpComponent['uses']();
            $app = new AtkWpApp($this);
            $app->initWpLayout($view, $this->defaultLayout, $this->pluginName);
            $app->execute();
        } catch (Exception $e) {
            $this->caughtException($e);
        }
    }

    /**
     * Output ajax call in Wp.
     * This is an overall catch ajax request for Wordpress admin and front.
     */
    public function wpAjaxExecute()
    {
        if ($this->config->getConfig('plugin/use_nounce', false)) {
            check_ajax_referer($this->pluginName);
        }

        $this->ajaxMode = true;
        $this->wpComponent = $this->componentCtrl->searchComponentByKey($_REQUEST['atkwp']);

        $name = $this->pluginName;

        // check if this component has been output more than once
        // and adjust name accordingly.
        if ($count = @$_REQUEST['atkwp-count']) {
            $name = $this->pluginName.'-'.$count;
        }

        try {
            $view = new $this->wpComponent['uses']();
            $app = new AtkWpApp($this);
            $app->initWpLayout($view, $this->defaultLayout, $name);
            $app->execute($this->ajaxMode);
        } catch (Exception $e) {
            $this->caughtException($e);
        }
        die();
    }

    /**
     * Dashboard output.
     *
     * @param string $key
     * @param aray   $dashboard
     * @param bool   $configureMode
     *
     * @throws Exception
     */
    public function wpDashboardExecute($key, $dashboard, $configureMode = false)
    {
        $this->wpComponent = $this->componentCtrl->searchComponentByType('dashboard', $dashboard['id']);

        try {
            $view = new $this->wpComponent['uses'](['configureMode' => $configureMode]);
            $app = new AtkWpApp($this);
            $app->initWpLayout($view, $this->defaultLayout, $this->pluginName);
            $app->execute();
        } catch (Exception $e) {
            $this->caughtException($e);
        }
    }

    /**
     * Output metabox view in Wp.
     *
     * @param \WP_Post $post  The wordpress post.
     * @param array    $param The param set in metabox configuration.
     *
     * @throws Exception
     */
    public function wpMetaBoxExecute(\WP_Post $post, array $param)
    {
        //set the view to output.
        $this->wpComponent = $this->componentCtrl->searchComponentByType('metaBox', $param['id']);

        try {
            $view = new $this->wpComponent['uses'](['args' => $param['args']]);
            $app = new AtkWpApp($this);
            $metaBox = $app->initWpLayout($view, $this->defaultLayout, $this->pluginName);
            $metaBox->setFieldInput($post->ID, $this->componentCtrl);
            $app->execute();
        } catch (Exception $e) {
            $this->caughtException($e);
        }
    }

    /**
     * Output shortcode view in Wordpress.
     *
     * @param array $shortcode
     * @param array $args
     *
     * @throws Exception
     *
     * @return string
     */
    public function wpShortcodeExecute($shortcode, $args)
    {
        $this->wpComponent = $shortcode;
        $this->componentCount++;

        try {
            $view = new $this->wpComponent['uses'](['args' => $args]);
            $app = new AtkWpApp($this);
            $app->initWpLayout($view, $this->defaultLayout, $this->pluginName.'-'.$this->componentCount);
            return $app->render(false);
        } catch (Exception $e) {
            $this->caughtException($e);
        }
    }
}
