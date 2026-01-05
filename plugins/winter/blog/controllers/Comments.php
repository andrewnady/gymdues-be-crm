<?php

namespace Winter\Blog\Controllers;

use Backend\Classes\Controller;
use BackendMenu;

class Comments extends Controller
{
    public $implement = [
        \Backend\Behaviors\FormController::class,
        \Backend\Behaviors\ListController::class,
    ];

    public $formConfig = 'config_form.yaml';
    public $listConfig = 'config_list.yaml';

    public $requiredPermissions = ['winter.blog.manage_comments'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Winter.Blog', 'blog', 'comments');
    }

    /**
     * Bulk approve selected comments
     */
    public function onApprove()
    {
        $checkedIds = post('checked', []);

        if (empty($checkedIds) || !is_array($checkedIds)) {
            throw new \ApplicationException('Please select comments to approve.');
        }

        $comments = \Winter\Blog\Models\Comment::whereIn('id', $checkedIds)->get();

        foreach ($comments as $comment) {
            $comment->is_approved = true;
            $comment->save();
        }

        \Flash::success('Comments approved successfully.');

        return $this->listRefresh();
    }
}

