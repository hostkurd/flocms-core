<?php
namespace FloCMS\Core;

class Model{
    
    /**
     * Connected on first use (see __get), so creating a model never touches the database.
     */
    protected Database $db;

    public function __construct(){
        // Unset so the first access goes through __get() and connects lazily.
        unset($this->db);
    }

    /**
     * @throws DatabaseNotConfiguredException when DB_NAME / DB_USERNAME are empty.
     * @throws DatabaseConnectionException when the server cannot be reached.
     */
    public function __get(string $name)
    {
        if ($name === 'db') {
            $db = App::db();
            if (!$db) {
                throw new DatabaseNotConfiguredException();
            }

            return $this->db = $db;
        }

        trigger_error('Undefined property: ' . static::class . '::$' . $name, E_USER_WARNING);
        return null;
    }

    public function pagingArray($pageId, $limit, $total): array
    {
        return Pagination::meta($pageId, $limit, $total);
    }


    private function GenPagePath($pageId, $curId, $PageLink){
        $result = '';

        $result .= '<li class="page-item '.($pageId == $curId ? 'active': '').'">';
        $result .= $pageId!=$curId?'<a class="page-link" href="'.$PageLink.$curId.'">'.$curId.'</a>': '<span class="page-link">'.$curId.'</span>';

        $result .= '</li>';

        return $result;
    }

    public function paginationData($pageid, $limit, $total, $controller,$subControl = null, $role='admin', $paramS = 1){ 
        $next_str = 'Next';
        $prev_str = 'Prev';
        $plusD = '';
        $minD = '';
        $path =SITE_URI.DS.ACTIVE_LANG_PATH.'admin/'.$controller.'/list/';
        if($subControl != null){$path =SITE_URI.DS.ACTIVE_LANG_PATH.'admin/'.$controller.'/'.$subControl.'/';}
        
    
        if($role == 'user'){
            $path =SITE_URI.DS.ACTIVE_LANG.DS.$controller.'/page/';
        }
    
        $page2Link='';
        $pageLast='';
        $pageBeforeLast='';
        $ellipsis = '';
        $pageid = (int)$pageid;
        if ($paramS !=1){
            $total = 1;
        }
    
        $pageidPlus = $pageid + 1;
        $pageidMin = $pageid - 1;
    
        $nextLink = '<a href="'.$path.$pageidPlus .'" class="page-link" aria-label="Next page">'.$next_str.'</a>';
        $prevLink = '<a href="'.$path.$pageidMin .'" class="page-link" aria-label="Previous page">'.$prev_str.'</a>';
        $total_pages = ceil($total/$limit);
        $beforelastid =$total_pages-1;
    
        if($pageid >= $total_pages){
            $plusD = 'disabled';
            $nextLink = '<span class="page-link">'.$next_str.'</span>';
        }
        if ($pageid <= 1){
            $minD = 'disabled';
            $prevLink ='<span class="page-link">'.$prev_str.'</span>';
        }
    
        $totalViewed = $limit * $pageid;
    
        if($totalViewed > $total){
            $totalViewed = $total;
        }
    
        //Head
        $html = "<div class=\"card-footer px-3 border-0 d-flex flex-column flex-lg-row align-items-center justify-content-between\">";
        $html .= "<nav aria-label=\"Page navigation\">";
        $html .= "<ul class=\"pagination mb-0\">";
    
        // Content
        $html .= "<li class=\"page-item\">".$prevLink."</li>";
    
        for($i=1; $i<=$total_pages; $i++){
            
            $html .= Self::GenPagePath($pageid,$i,$path); 
    
        }
        
        $html .= "<li class=\"page-item\">".$nextLink."</li>";
    
        // Foot
        $html .= "</ul>";
        $html .= "</nav>";
        $html .= "<div class=\"fw-normal small mt-4 mt-lg-0\">Showing <b>".$totalViewed."</b> out of <b>".$total."</b> entries</div>";
        $html .= "</div>";
    
        Return $html;
    }
}
