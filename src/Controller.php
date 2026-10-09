<?php
namespace FloCMS\Core;

use FloCMS\Core\Http\Request;

class Controller{

    protected $data;
    protected $model;
    protected $params;
    protected Request $request;

    /**
     * Permission required per action method, e.g. ['admin_edit' => 'users.manage'].
     * The '*' key applies to every action not listed; null means no check.
     *
     * @var array<string, string|null>
     */
    protected array $actionPermissions = [];

    public function setRequest(Request $request): void {
        $this->request = $request;
    }
    
    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return mixed
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * @return mixed
     */
    public function getParams()
    {
        return $this->params;
    }


    /**
     * The permission required to run $method, or null when none is required.
     */
    public function permissionFor(string $method): ?string
    {
        $method = strtolower($method);

        foreach ($this->actionPermissions as $action => $permission) {
            if (strtolower((string) $action) === $method) {
                return $permission;
            }
        }

        return $this->actionPermissions['*'] ?? null;
    }

    public function __construct($data=array()){
        $this->data = $data;
        $this->params = App::getRouter()->getParams();
    }



}