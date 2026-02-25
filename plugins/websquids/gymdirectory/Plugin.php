<?php namespace websquids\Gymdirectory;

use System\Classes\PluginBase;
use websquids\Gymdirectory\Console\BatchGenerateBestGymsPages;
use websquids\Gymdirectory\Console\GenerateBestGymsPages;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name'        => 'Gym Directory',
            'description' => 'Gym directory and API (includes custom blog API so it is never overwritten by Winter.Blog updates)',
            'author'      => 'Websquids',
        ];
    }

    /**
     * Load after Winter.Blog so our api/v1/posts routes take precedence over the default plugin.
     */
    public $require = ['Winter.Blog'];

    public function registerComponents()
    {
    }

    public function registerSettings()
    {
    }

    public function register()
    {
        $this->registerConsoleCommand('gymdirectory:generate-best-gyms-pages', GenerateBestGymsPages::class);
        $this->registerConsoleCommand('gymdirectory:batch-generate-best-gyms-pages', BatchGenerateBestGymsPages::class);
    }


    public function registerNavigation()
    {
        return [
            'gymdirectory' => [
                'label' => 'Gym Directory',
                'url' => \Backend::url('websquids/gymdirectory/gyms'),
                'icon' => 'icon-dumbbell',
                'permissions' => ['websquids.gymdirectory.*'],
                'order' => 500,
                'sideMenu' => [
                    'gyms' => [
                        'label' => 'Gyms',
                        'icon' => 'icon-building',
                        'url' => \Backend::url('websquids/gymdirectory/gyms'),
                        'permissions' => ['websquids.gymdirectory.manage_gyms'],
                    ],
                    'staticpages' => [
                        'label' => 'Static Pages',
                        'icon' => 'icon-file-text',
                        'url' => \Backend::url('websquids/gymdirectory/staticpages'),
                        'permissions' => ['websquids.gymdirectory.manage_static_pages'],
                    ],
                    'contactsubmissions' => [
                        'label' => 'Contact Submissions',
                        'icon' => 'icon-envelope',
                        'url' => \Backend::url('websquids/gymdirectory/contactsubmissions'),
                        'permissions' => ['websquids.gymdirectory.manage_contact_submissions'],
                    ],
                    'newslettersubscriptions' => [
                        'label' => 'Newsletter Subscriptions',
                        'icon' => 'icon-envelope-o',
                        'url' => \Backend::url('websquids/gymdirectory/newslettersubscriptions'),
                        'permissions' => ['websquids.gymdirectory.manage_newsletter_subscriptions'],
                    ],
                ],
            ],
        ];
    }
}
