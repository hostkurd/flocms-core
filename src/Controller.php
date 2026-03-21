<?php
namespace FloCMS\Core;

use FloCMS\Core\Http\Request;

class Controller{

    protected $data;
    protected $model;
    protected $params;
    protected Request $request;

    /**
     * null  => use router layout
     * ''    => no layout
     * other => custom layout name
     */
    protected ?string $layout = null;

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

    public function __construct($data=array()){
        $this->data = $data;
        $this->params = App::getRouter()->getParams();
    }

    public function setLayout(?string $layout): void
    {
        $this->layout = $layout;
    }

    public function disableLayout(): void
    {
        $this->layout = '';
    }

    public function getLayout(): ?string
    {
        return $this->layout;
    }

}