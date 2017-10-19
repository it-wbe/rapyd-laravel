<?php

namespace Zofe\Rapyd\DataFilter;

use Zofe\Rapyd\DataForm\DataForm;
use Zofe\Rapyd\Persistence;
use Collective\Html\FormFacade as Form;
use Illuminate\Support\Facades\DB;

class DataFilter extends DataForm
{

    public $cid;
    public $source;
    protected $process_url = '';
    protected $reset_url = '';
    public $attributes = array('class'=>'form-inline');
    /**
     *
     * @var \Illuminate\Database\Query\Builder
     */
    public $query;

    /**
     * @param $source
     *
     * @return static
     */
    public static function source($source = null)
    {
        $ins = new static();
        $ins->source = $source;
        $ins->query = $source;
        if (is_object($source) && (is_a($source, "\Illuminate\Database\Eloquent\Builder") ||
                                  is_a($source, "\Illuminate\Database\Eloquent\Model"))) {
            $ins->model = $source->getModel();
        }
        $ins->cid = $ins->getIdentifier();
        $ins->sniffStatus();
        $ins->sniffAction();

        return $ins;
    }

    protected function table($table)
    {
        $this->query = DB::table($table);

        return $this->query;
    }

    protected function sniffAction()
    {

        $this->reset_url = $this->url->remove('ALL')->append('reset'.$this->cid, 1)->get();
        $this->process_url = $this->url->remove('ALL')->append('search'.$this->cid, 1)->get();

        ///// search /////
        if ($this->url->value('search')) {
            $this->action = "search";

            Persistence::save();
        }
        ///// reset /////
        elseif ($this->url->value("reset")) {
            $this->action = "reset";

            Persistence::clear();
        } else {

            Persistence::clear();
        }
    }

    protected function process()
    {
        $this->method = 'GET';

        //database save
        switch ($this->action) {
            case "search":

                // prepare the WHERE clause
                foreach ($this->fields as $field) {
                    if($field->name=='show_rows_crud')
                        continue;

                    $field->getValue();
                    $field->getNewValue();
                    $value = $field->new_value;

                    //query scope
                    $query_scope = $field->query_scope;
                    $query_scope_params = $field->query_scope_params;
                    if ($query_scope) {

                        if (is_a($query_scope, '\Closure')) {

                            array_unshift($query_scope_params, $value);
                            array_unshift($query_scope_params, $this->query);
                            $this->query = call_user_func_array($query_scope, $query_scope_params);

                        } elseif (isset($this->model) && method_exists($this->model, "scope".$query_scope)) {
                            
                            $query_scope = "scope".$query_scope;
                            array_unshift($query_scope_params, $value);
                            array_unshift($query_scope_params, $this->query);
                            $this->query = call_user_func_array([$this->model, $query_scope], $query_scope_params);
                            
                        } 
                        continue;
                    }

                    //detect if where should be deep (on relation)
                    $deep_where = false;

                    if (isset($this->model) && $field->relation != null) {
                        $rel_type = get_class($field->relation);

                        if (
                            is_a($field->relation, 'Illuminate\Database\Eloquent\Relations\HasOne')
                            || is_a($field->relation, 'Illuminate\Database\Eloquent\Relations\HasMany')
                            || is_a($field->relation, 'Illuminate\Database\Eloquent\Relations\BelongsTo')
                            || is_a($field->relation, 'Illuminate\Database\Eloquent\Relations\BelongsToMany')
                        ){
                            if (
                                is_a($field->relation, 'Illuminate\Database\Eloquent\Relations\BelongsTo') and
                                in_array($field->type, array('select', 'radiogroup', 'autocomplete'))
                            ){
                                    $deep_where = false;
                            } else {
                                $deep_where = true;
                            }

                        }
                    }
                    
                    if ($value != "" or (is_array($value)  and count($value)) ) {
                        if (strpos($field->name, "_copy") > 0) {
                            $name = substr($field->db_name, 0, strpos($field->db_name, "_copy"));
                        } else {
                            $name = $field->db_name;
                        }

                        //$value = $field->value;
                       
                        if ($deep_where) {
                            //exception for multiple value fields on BelongsToMany
                            if (
                                (is_a($field->relation, 'Illuminate\Database\Eloquent\Relations\BelongsToMany')
                                || is_a($field->relation, 'Illuminate\Database\Eloquent\Relations\BelongsTo')
                                ) and
                                in_array($field->type, array('tags','checks','multiselect'))
                            ){
                                  $values = explode($field->serialization_sep, $value);

                                  if ($field->clause == 'wherein') {
                                      $this->query = $this->query->whereHas($field->rel_name, function ($q) use ($field, $values) {
                                          $q->whereIn($field->rel_fq_key, $values);
                                      });
                                  }

                                  if ($field->clause == 'where') {
                                      foreach ($values as $v) {
                                          $this->query = $this->query->whereHas($field->rel_name, function ($q) use ($field, $v) {
                                              $q->where($field->rel_fq_key,'=', $v);
                                          });
                                      }
                                  }
                                continue;
                            }
                            switch ($field->clause) {
                                case "like":
                                    $this->query = $this->query->whereHas($field->rel_name, function ($q) use ($field, $value) {
                                        $q->where($field->rel_field, 'LIKE', '%' . $value . '%');
                                    });
                                    break;
                                case "orlike":
                                    $this->query = $this->query->orWhereHas($field->rel_name, function ($q) use ($field, $value) {
                                        $q->where($field->rel_field, 'LIKE', '%' . $value . '%');
                                    });
                                    break;
                                case "where":
                                    $this->query = $this->query->whereHas($field->rel_name, function ($q) use ($field, $value) {
                                        $q->where($field->rel_field, $field->operator, $value);
                                    });
                                    break;
                                case "orwhere":
                                    $this->query = $this->query->orWhereHas($field->rel_name, function ($q) use ($field, $value) {
                                        $q->where($field->rel_field, $field->operator, $value);
                                    });
                                    break;
                                case "wherebetween":
                                    $values = explode($field->serialization_sep, $value);
                                    $this->query = $this->query->whereHas($field->rel_name, function ($q) use ($field, $values) {

                                        if ($values[0] != '' and $values[1] == '') {
                                            $q->where($field->rel_field, ">=", $values[0]);
                                        } elseif ($values[0] == '' and $values[1] != '') {
                                            $q->where($field->rel_field, "<=", $values[1]);
                                        } elseif ($values[0] != '' and $values[1] != '') {

                                            //we avoid "whereBetween" because a bug in laravel 4.1
                                            $q->where(
                                                function ($query) use ($field, $values) {
                                                    return $query->where($field->rel_field, ">=", $values[0])
                                                                 ->where($field->rel_field, "<=", $values[1]);
                                                }
                                            );
                                        }

                                    });
                                    break;
                                case "orwherebetween":
                                    $values = explode($field->serialization_sep, $value);
                                    $this->query = $this->query->orWhereHas($field->rel_name, function ($q) use ($field, $values) {

                                        if ($values[0] != '' and $values[1] == '') {
                                            $q->orWhere($field->rel_field, ">=", $values[0]);
                                        } elseif ($values[0] == '' and $values[1] != '') {
                                            $q->orWhere($field->rel_field, "<=", $values[1]);
                                        } elseif ($values[0] != '' and $values[1] != '') {

                                            //we avoid "whereBetween" because a bug in laravel 4.1
                                            $q->orWhere(
                                                function ($query) use ($field, $values) {
                                                    return $query->where($field->rel_field, ">=", $values[0])
                                                                 ->where($field->rel_field, "<=", $values[1]);
                                                }
                                            );
                                        }

                                    });
                                    break;
                            }

                        //not deep, where is on main entity
                        } else {
//                            dd($field);
                            switch ($field->clause) {
                                case "like":
                                    $this->query = $this->query->where($name, 'LIKE', '%' . $value . '%');
//                                    dd($this->query->where($name, 'LIKE', '%' . $value . '%'));
                                    break;
                                case "orlike":
                                    $this->query = $this->query->orWhere($name, 'LIKE', '%' . $value . '%');
                                    break;
                                case "where":
                                    $this->query = $this->query->where($name, $field->operator, $value);
                                    break;
                                case "orwhere":
                                    $this->query = $this->query->orWhere($name, $field->operator, $value);
                                    break;
                                case "wherein":
                                    $this->query = $this->query->whereIn($name,  explode($field->serialization_sep, $value));
                                    break;
                                case "wherebetween":
                                    $values = explode($field->serialization_sep, $value);
                                    if (count($values)==2) {

                                        if ($values[0] != '' and $values[1] == '') {
                                            $this->query = $this->query->where($name, ">=", $values[0]);
                                        } elseif ($values[0] == '' and $values[1] != '') {
                                            $this->query = $this->query->where($name, "<=", $values[1]);
                                        } elseif ($values[0] != '' and $values[1] != '') {

                                            //we avoid "whereBetween" because a bug in laravel 4.1
                                            $this->query =  $this->query->where(
                                                function ($query) use ($name, $values) {
                                                     return $query->where($name, ">=", $values[0])
                                                                  ->where($name, "<=", $values[1]);
                                                }
                                            );

                                        }

                                    }

                                    break;
                                case "orwherebetween":
                                    $values = explode($field->serialization_sep, $value);
                                    if (count($values)==2) {
                                        if ($values[0] != '' and $values[1] == '') {
                                            $this->query = $this->query->orWhere($name, ">=", $values[0]);
                                        } elseif ($values[0] == '' and $values[1] != '') {
                                            $this->query = $this->query->orWhere($name, "<=", $values[1]);
                                        } elseif ($values[0] != '' and $values[1] != '') {
                                            //we avoid "whereBetween" because a bug in laravel 4.1
                                            $this->query =  $this->query->orWhere(
                                                function ($query) use ($name, $values) {
                                                    return $query->where($name, ">=", $values[0])
                                                                 ->where($name, "<=", $values[1]);
                                                }
                                            );
                                        }

                                    }

                                    break;

                                case 'custom':
                                    if(is_numeric($value)){
                                         $this->query->where($name,"=",$value);
                                    }
                                    else{// parse
                                        $this->query =  $this->ParseTypeNumberForFilter($name,$value);
                                    }
                                    break;

                            }
                        }

                    }
                }
                break;
            case "reset":
                $this->process_status = "show";

                return true;
                break;
            default:
                return false;
        }
    }

    private function ParseTypeNumberForFilter($field_name,$value){
//        $query;
//        dump($value);
       $val_arr = str_split($value,1);
       $arr_count = count($val_arr);

       $temp_where ='';
        $temp_or = false;

        foreach ($val_arr as $val_key=>$val_val){
            if($val_key!=0&&($val_val=='<'||$val_val=='>'||$val_val=='a'||$val_val=='o'||$arr_count==(1+$val_key))){ // робимо наступний where
                if($arr_count==(1+$val_key)){// якщо останній
                    $temp_where.=$val_val;
                    $temp_or = $this->createWhere($field_name,$temp_where,$temp_or); // parse one where
                }else{
                    $temp_or = $this->createWhere($field_name,$temp_where,$temp_or); // parse one where
                    $temp_where = '';
                    $temp_where.=$val_val;
                }
            }else{ // витягаэмо значення для поточного where
                $temp_where.=$val_val;
            }

        }
//            dump($this->query);
//        exit;
            return $this->query;
    }
    function createWhere($field_name,$str_where,$or=false){
        $arr_command = str_split($str_where,1);
//        $temp_command =
        if($arr_command[0]=='<'&&$arr_command[1]!='='){// <
           $val = substr($str_where,1);// забираэмо value
//            dump('<');
//            dump($val);
//            dump($or);
            $this->addWhere($field_name,'<',$val,$or);
            return false;
        }
        if($arr_command[0]=='>'&&$arr_command[1]!='='){// >
            $val = substr($str_where,1);// забираэмо value
//            dump('>');
//            dump($val);
//            dump($or);
            $this->addWhere($field_name,'>',$val,$or);
            return false;
        }
        if($arr_command[0]=='<'&&$arr_command[1]=='='){// <=
            $val = substr($str_where,2);// забираэмо value
//            dump('<=');
//            dump($val);
//            dump($or);
            $this->addWhere($field_name,'<=',$val,$or);
            return false;
        }
        if($arr_command[0]=='>'&&$arr_command[1]=='='){// >=
            $val = substr($str_where,2);// забираэмо value
//            dump('>=');
//            dump($val);
//            dump($or);
            $this->addWhere($field_name,'>=',$val,$or);
            return false;
        }
        if($arr_command[0]=='o'&&$arr_command[1]=='r'){// or
            $val = substr($str_where,2);// забираэмо value
            if(is_numeric($val)){
//                dd($val);
                $this->addWhere($field_name,'=',$val,true);
            }
//            dump('or');
//            dump($val);
//            dump($or);
            return true;
        }
        $val = str_split($str_where,1);// забираэмо value
        $temp_val = null;
        foreach ($val as $number){
            if($number!='<'&&$number!='>'&&$number!='o'){
                $temp_val .=$number;
            }else{
                break;
            }
        }
        $this->addWhere($field_name,'=',$temp_val,$or);
    }

    function addWhere($field_name,$mark,$val,$or)
    {
        if ($or) {
            $this->query->orWhere($field_name, $mark, $val);
        } else {
            $this->query->where($field_name, $mark, $val);
        }
    }

}
