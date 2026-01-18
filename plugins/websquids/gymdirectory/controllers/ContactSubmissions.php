<?php namespace websquids\Gymdirectory\Controllers;

use Backend\Classes\Controller;
use BackendMenu;

class ContactSubmissions extends Controller
{
    public $implement = [
        'Backend\Behaviors\ListController',
        'Backend\Behaviors\FormController',
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('websquids.Gymdirectory', 'gymdirectory', 'contactsubmissions');
    }

    /**
     * Mark submission as read
     */
    public function markAsRead($recordId = null)
    {
        $model = $this->formFindModelObject($recordId);
        $model->markAsRead();
        
        \Flash::success('Contact submission marked as read.');
        return $this->listRefresh();
    }
}

