<?php namespace Dimti\Groupfields;

use Backend;
use Dimti\GroupFields\FormWidgets\Group;
use System\Classes\PluginBase;

/**
 * groupfields Plugin Information File
 */
class Plugin extends PluginBase
{
    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'groupfields',
            'description' => 'No description provided yet...',
            'author'      => 'dimti',
            'icon'        => 'icon-leaf'
        ];
    }

    /**
     * Register method, called when the plugin is first registered.
     *
     * @return void
     */
    public function register()
    {

    }

    /**
     * Boot method, called right before the request route.
     *
     * @return array
     */
    public function boot()
    {

    }

    /**
     * Registers any front-end components implemented in this plugin.
     *
     * @return array
     */
    public function registerComponents()
    {
        return []; // Remove this line to activate

        return [
            'Dimti\Groupfields\Components\MyComponent' => 'myComponent',
        ];
    }

    /**
     * Registers any back-end permissions used by this plugin.
     *
     * @return array
     */
    public function registerPermissions()
    {
        return []; // Remove this line to activate

        return [
            'dimti.groupfields.some_permission' => [
                'tab' => 'groupfields',
                'label' => 'Some permission'
            ],
        ];
    }

    /**
     * Registers back-end navigation items for this plugin.
     *
     * @return array
     */
    public function registerNavigation()
    {
        return []; // Remove this line to activate

        return [
            'groupfields' => [
                'label'       => 'groupfields',
                'url'         => Backend::url('dimti/groupfields/mycontroller'),
                'icon'        => 'icon-leaf',
                'permissions' => ['dimti.groupfields.*'],
                'order'       => 500,
            ],
        ];
    }


    public function registerFormWidgets()
    {
        return [
            Group::class => 'group',
        ];
    }
}
