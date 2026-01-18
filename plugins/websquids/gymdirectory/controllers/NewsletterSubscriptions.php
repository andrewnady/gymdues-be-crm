<?php namespace websquids\Gymdirectory\Controllers;

use Backend\Classes\Controller;
use BackendMenu;

class NewsletterSubscriptions extends Controller
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
        BackendMenu::setContext('websquids.Gymdirectory', 'gymdirectory', 'newslettersubscriptions');
    }

    /**
     * Unsubscribe a subscription
     */
    public function unsubscribe($recordId = null)
    {
        $model = $this->formFindModelObject($recordId);
        $model->unsubscribe();
        
        \Flash::success('Newsletter subscription unsubscribed.');
        return $this->listRefresh();
    }

    /**
     * Resubscribe a subscription
     */
    public function resubscribe($recordId = null)
    {
        $model = $this->formFindModelObject($recordId);
        $model->resubscribe();
        
        \Flash::success('Newsletter subscription resubscribed.');
        return $this->listRefresh();
    }
}

