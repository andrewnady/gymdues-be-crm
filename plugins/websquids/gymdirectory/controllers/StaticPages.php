<?php

namespace Websquids\Gymdirectory\Controllers;

use Backend\Classes\Controller;
use BackendMenu;

class StaticPages extends Controller
{
    public $implement = [
        \Backend\Behaviors\FormController::class,
        \Backend\Behaviors\ListController::class,
    ];

    public $formConfig = 'config_form.yaml';
    public $listConfig = 'config_list.yaml';

    public $requiredPermissions = ['websquids.gymdirectory.manage_static_pages'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Websquids.Gymdirectory', 'gymdirectory', 'staticpages');
    }
}

