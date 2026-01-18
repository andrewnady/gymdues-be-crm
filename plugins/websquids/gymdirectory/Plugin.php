<?php namespace websquids\Gymdirectory;

use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public function registerComponents()
    {
    }

    public function registerSettings()
    {
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
