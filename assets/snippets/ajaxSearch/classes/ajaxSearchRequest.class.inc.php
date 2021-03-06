<?php
/**
 * -----------------------------------------------------------------------------
 * Snippet: AjaxSearch
 * ------------------------------------------------------------------------------
 * @package  AjaxSearchRequest
 *
 * @author       Coroico - www.evo.wangba.fr
 * @version      1.12.1
 * @date         27/10/2018
 *
 * Purpose:
 *    The AjaxSearchRequest class contains all functions and data used to manage the search SQL Request
 *
 */

if (! defined('GROUP_CONCAT_LENGTH')) {
    define('GROUP_CONCAT_LENGTH', 4096); // maximum length of the group concat
}

class AjaxSearchRequest
{
    /**
     * @var array
     */
    public $cfg;
    /**
     * @var null|bool
     */
    public $withContent;
    /**
     * @var null|array
     */
    public $scMain = array();
    /**
     * @var null|array
     */
    public $scJoined = array();
    /**
     * @var null|array
     */
    public $scTvs = array();
    /**
     * @var null|array
     */
    public $scCateg = array();
    /**
     * @var null|array
     */
    public $scTags = array();

    /**
     * @var null|string
     */
    public $asSelect;

    /** @var AjaxSearchUtil */
    public $asUtil;
    public $dbg;
    /**
     * @var string
     */
    public $pgCharset;

    public function __construct(&$asUtil, $pgCharset)
    {
        $this->asUtil =& $asUtil;
        $this->dbg = $asUtil->dbg;
        $this->pgCharset = $pgCharset;
        if ($this->dbg) {
            $this->asUtil->dbgRecord($this->pgCharset, "pgCharset");
        }
    }

    /**
     * doSearch : Do the search
     */
    public function doSearch($searchString, $advSearch, $cfg, $bsf, $fClause)
    {
        global $modx;
        $searchString = $modx->db->escape($searchString);
        $this->cfg = $cfg;
        $records = null;
        $results = array();
        $this->asSelect = '';
        if ($this->_initSearchContext($bsf)) {
            $fields = $this->_getFields();
            $from = $this->_getFrom($searchString, $advSearch);
            $where = $this->_getWhere();
            $groupBy = $this->_getGroupBy();
            $having = $this->_getHaving($searchString, $advSearch, $fClause);
            $orderBy = $this->_getOrderBy();
            $this->asSelect = "SELECT $fields FROM $from WHERE $where";
            $this->asSelect.= " GROUP BY $groupBy HAVING $having ORDER BY $orderBy";
            if ($this->scJoined !== null) {
                $modx->db->query("SET group_concat_max_len = " . GROUP_CONCAT_LENGTH . ";");
            }
            if ($this->dbg) {
                $this->asUtil->dbgRecord($this->_printSelect($this->asSelect), "Select");
            }
            $records = $modx->db->query($this->asSelect);
            if ($this->dbg) {
                $this->asUtil->dbgRecord("End of select");
            }
            $results = $this->_appendTvs($records);
            $modx->db->freeResult($records);
        }
        return $results;
    }

    /**
     * Check and initialize the description of the tables & content fields
     */
    public function _initSearchContext($bsf)
    {
        $this->scMain = null;
        $this->scJoined = null;
        $this->scTvs = null;
        $this->scCateg = null;
        $this->scTags = null;
        $mainDefined = false;
        $this->withContent = false;
        $part = explode('|', $this->cfg['whereSearch']);
        foreach ($part as $p) {
            list($ptable, $pfields) = explode(':', $p . ':');
            switch ($ptable) {
                case 'content':
                    $this->scMain = array(
                        'tb_name' => $this->_getShortTableName('site_content'),
                        'tb_alias' => 'sc',
                        'id' => 'id',
                        'searchable' => array('pagetitle', 'longtitle', 'description', 'alias', 'introtext', 'menutitle', 'content'),
                        'displayed' => array('pagetitle', 'longtitle', 'description', 'alias', 'introtext', 'template', 'menutitle', 'content'),
                        'date' => array('publishedon'),
                        'filters' => array(),
                        'jfilters' => array(),
                        'append' => array()
                    );
                    if ($pfields != '') {
                        $this->scMain['searchable'] = (strtolower($pfields) === 'null') ?
                            array() :
                            explode(',', $pfields);
                    }
                    $this->withContent = true;
                    $mainDefined = true;

                    if ($this->_validListIDs($bsf['listIds'])) {
                        $this->scMain['filters'][] = array(
                            'field' => 'id',
                            'oper' => $bsf['oper'],
                            'value' => $bsf['listIds']
                        );
                    }
                    $this->scMain['filters'][] = array('field' => 'published', 'oper' => '=', 'value' => '1');
                    $this->scMain['filters'][] = array('field' => 'searchable', 'oper' => '=', 'value' => '1');
                    $this->scMain['filters'][] = array('field' => 'deleted', 'oper' => '=', 'value' => '0');

                    if (($this->cfg['hideMenu'] == 0) || ($this->cfg['hideMenu'] == 1)) {
                        $this->scMain['filters'][] = array(
                            'field' => 'hidemenu',
                            'oper' => '=',
                            'value' => $this->cfg['hideMenu']
                        );
                    }

                    if ($this->cfg['hideLink'] == 1) {
                        $this->scMain['filters'][] = array(
                            'field' => 'type',
                            'oper' => '=',
                            'value' => '\'document\'');
                    }

                    if ($this->_validListIDs($this->cfg['docgrp'])) {
                        $this->scMain['jfilters'][] = array(
                            'tb_name' => $this->_getShortTableName('document_groups'),
                            'tb_alias' => 'dg',
                            'main' => 'id',
                            'join' => 'document',
                            'field' => 'document_group',
                            'oper' => 'in',
                            'value' => $this->cfg['docgrp'],
                            'or' => array('field' => 'privateweb', 'oper' => '=', 'value' => '0')
                        );
                    } else {
                        $this->scMain['filters'][] = array('field' => 'privateweb', 'oper' => '=', 'value' => '0');
                    }
                    break;
                case 'tv':
                    $this->scJoined[] = array(
                        'tb_name' => $this->_getShortTableName('site_tmplvar_contentvalues'),
                        'tb_alias' => 'tv',
                        'id' => 'id',
                        'main' => 'id',
                        'join' => 'contentid',
                        'searchable' => array('value'),
                        'displayed' => array('value'),
                        'concat_separator' => ',',
                        'filters' => array(),
                        'jfilters' => array()
                    );
                    $j = count($this->scJoined) - 1;
                    if ($pfields !== '') {
                        $this->scJoined[$j]['searchable'] = strtolower($pfields) === 'null' ?
                            array() :
                            explode(',', $pfields);
                    }
                    break;
                case 'jot':
                    $this->scJoined[] = array(
                        'tb_name' => $this->_getShortTableName('jot_content'),
                        'tb_alias' => 'jot',
                        'id' => 'id',
                        'main' => 'id',
                        'join' => 'uparent',
                        'searchable' => array('content'),
                        'displayed' => array('content'),
                        'concat_separator' => ', ',
                        'filters' => array()
                    );
                    $j = count($this->scJoined) - 1;
                    if ($pfields !== '') {
                        $this->scJoined[$j]['searchable'] = strtolower($pfields) === 'null' ?
                            array() :
                            explode(',', $pfields);
                    }

                    $j = count($this->scJoined) - 1;
                    $this->scJoined[$j]['filters'][] = array('field' => 'published', 'oper' => '=', 'value' => '1');
                    break;
                case 'maxigallery':
                    $this->scJoined[] = array(
                        'tb_name' => $this->_getShortTableName('maxigallery'),
                        'tb_alias' => 'gal',
                        'id' => 'id',
                        'main' => 'id',
                        'join' => 'gal_id',
                        'searchable' => array('title', 'descr'),
                        'displayed' => array('title', 'descr', 'filename'),
                        'concat_separator' => ', ',
                        'filters' => array()
                    );
                    $j = count($this->scJoined) - 1;
                    if ($pfields !== '') {
                        $this->scJoined[$j]['searchable'] = strtolower($pfields) === 'null' ?
                            array() :
                            explode(',', $pfields);
                    }

                    $j = count($this->scJoined) - 1;
                    $this->scJoined[$j]['filters'][] = array('field' => 'hide', 'oper' => '=', 'value' => '0');
                    break;
                default:
                    if (function_exists($ptable)) {
                        $main = null;
                        $joined = null;
                        $ptable($main, $joined, $bsf, $pfields);
                        if ($main) {
                            $this->scMain = $main;
                            $mainDefined = true;
                        }
                        if ($joined && count($joined['displayed']) > 0) {
                            $this->scJoined[] = $joined;
                        }
                    }
                    break;
            }
        }


        if (!empty($this->cfg['withTvs'])) {
            $this->scTvs = array(
                'names' => array(),
                'tvs' => array(),
            );
            $this->scTvs['names'] = $this->_getTvsArray($this->cfg['withTvs']);
            if ($this->dbg) {
                $this->asUtil->dbgRecord($this->scTvs['names'], 'initSearchContext - withTvs');
            }
            $this->scTvs['tvs'] = $this->_getTvSubSelect($this->scTvs['names'], '', 'tv', 'simple');
        }


        $categ_array = explode(':', $this->cfg['category']);
        if (!empty($categ_array[0])) {
            if (!isset($categ_array[1])) {
                $ctvs_array = $this->_getTvsArray($categ_array[0]);
                if ($this->dbg) {
                    $this->asUtil->dbgRecord($ctvs_array, 'initSearchContext - category');
                }
                $stv = $this->_getTvSubSelect($ctvs_array, 'category', 'cg', 'simple');
                $this->scCateg = $stv[0];
            } else {
                $fCateg = $categ_array[1];
                /**
                 * fCateg returns an array with 'tb_alias,id,value,sql fields
                 */
                $this->scCateg = $fCateg($categ_array[0]);
            }
        }


        $tags_array = explode(':', $this->cfg['tags']);
        if (!empty($tags_array[0])) {
            if (!isset($tags_array[1])) {
                $ttvs_array = $this->_getTvsArray($tags_array[0]);
                if ($this->dbg) {
                    $this->asUtil->dbgRecord($ttvs_array, 'initSearchContext - tags');
                }
                $stv = $this->_getTvSubSelect($ttvs_array, 'tags', 'tg', 'concat');
                $this->scTags = $stv[0];
            } else {
                $fTags = $tags_array[1];
                /**
                 * fTags returns an array with 'tb_alias,id,value,sql fields
                 */
                $this->scTags = $fTags($tags_array[0]);
            }
        }

        if ($this->asUtil->dbgRes) {
            $this->asUtil->dbgRecord($this->scMain, 'Search context main ' . $this->scMain['tb_name']);
            if ($this->scJoined !== null) {
                foreach ($this->scJoined as $joined) {
                    $this->asUtil->dbgRecord($joined, 'Search context joined ' . $joined['tb_name']);
                }
            }
        }

        return $mainDefined;
    }
    /**
     * Get the fields clause of the AS query
     */
    public function _getFields()
    {
        $fields = array();
        $mpref = $this->scMain['tb_alias'];

        $fields[] = $mpref . '.' . $this->scMain['id'];

        if (isset($this->scMain['displayed'])) {
            foreach ($this->scMain['displayed'] as $displayed) {
                $fields[] = $mpref . '.' . $displayed;
            }
        }

        if (isset($this->scMain['date'])) {
            foreach ($this->scMain['date'] as $date) {
                $fields[] = $mpref . '.' . $date;
            }
        }

        if ($this->scJoined !== null) {
            foreach ($this->scJoined as $joined) {
                $jpref = $joined['tb_alias'];
                $f = 'GROUP_CONCAT( DISTINCT CAST(n' . $jpref . '.' . $joined['id'] . ' AS CHAR)';
                $f.= ' SEPARATOR "," ) AS ' . $jpref . '_' . $joined['id'];
                $fields[] = $f;
            }
        }

        if ($this->scJoined !== null) {
            foreach ($this->scJoined as $joined) {
                $jpref = $joined['tb_alias'];
                $nbd = count($joined['displayed']);
                for ($d = 0; $d < $nbd; $d++) {
                    $f = 'GROUP_CONCAT( DISTINCT n' . $jpref . '.' . $joined['displayed'][$d];
                    $f.= ' SEPARATOR "' . $joined['concat_separator'] . '" ) AS ' . $jpref . '_' . $joined['displayed'][$d];
                    $fields[] = $f;
                }
            }
        }

        if (isset($this->scTvs['tvs'])) {
            foreach($this->scTvs['tvs'] as $scTv) {
                $f = $scTv['tb_alias'] . '.' . $scTv['displayed'] . ' AS `' . $scTv['name'] . '`';
                $fields[] = $f;
            }
        }

        if ($this->scCateg !== null) {
            $f = $this->scCateg['tb_alias'] . '.' . $this->scCateg['displayed'] . ' AS category';
            $fields[] = $f;
        }

        if ($this->scTags !== null) {
            $f = 'REPLACE( GROUP_CONCAT( DISTINCT ' . $this->scTags['tb_alias'] . '.' . $this->scTags['displayed'];
            $f.= ' SEPARATOR "," ), "||", ",") AS tags';
            $fields[] = $f;
        }

        return count($fields) > 0 ? implode(', ', $fields) : '*';
    }

    /**
     * Get the "FROM" clause of the AS query
     */
    public function _getFrom($searchString, $advSearch)
    {
        $from[] = $this->scMain['tb_name'] . ' ' . $this->scMain['tb_alias'];
        //left join with jfilter tables
        if (isset($this->scMain['jfilters'])) {
            foreach ($this->scMain['jfilters'] as $filter) {
                $f = 'LEFT JOIN ' . $filter['tb_name'] . ' ' . $filter['tb_alias'];
                $f.= ' ON ' . $this->scMain['tb_alias'] . '.' . $filter['main'] . ' = ' . $filter['tb_alias'] . '.' . $filter['join'];
                $from[] = $f;
            }
        }

        //left join with joined table
        if ($this->scJoined !== null) {
            foreach ($this->scJoined as $joined) {
                $jpref = 'n' . $joined['tb_alias'];
                $f = 'LEFT JOIN( ' . $this->_getSubSelect($joined, $searchString, $advSearch) . ' )' . ' AS ' . $jpref . ' ON ';
                $f.= $this->scMain['tb_alias'] . '.' . $joined['main'] . ' = ' . $jpref . '.' . $joined['join'];
                $from[] = $f;
            }
        }

        //left join with Tv defined as table field
        if (isset($this->scTvs['tvs'])) {
            foreach ($this->scTvs['tvs'] as $scTv) {
                $f = 'LEFT JOIN( ' . $scTv['sql'] . ' ) AS ' . $scTv['tb_alias'];
                $f.= ' ON ' . $this->scMain['tb_alias'] . '.' . $scTv['main'] . ' = ';
                $f.= $scTv['tb_alias'] . '.' . $scTv['join'];
                $from[] = $f;
            }
        }

        //left join with categories
        if ($this->scCateg !== null) {
            $f = 'LEFT JOIN( ' . $this->scCateg['sql'] . ' ) AS ' . $this->scCateg['tb_alias'];
            $f.= ' ON ' . $this->scMain['tb_alias'] . '.' . $this->scCateg['main'] . ' = ';
            $f.= $this->scCateg['tb_alias'] . '.' . $this->scCateg['join'];
            $from[] = $f;
        }

        //left join with tags
        if ($this->scTags !== null) {
            $f = 'LEFT JOIN( ' . $this->scTags['sql'] . ' ) AS ' . $this->scTags['tb_alias'];
            $f.= ' ON ' . $this->scMain['tb_alias'] . '.' . $this->scTags['main'] . ' = ';
            $f.= $this->scTags['tb_alias'] . '.' . $this->scTags['join'];
            $from[] = $f;
        }

        return implode(' ', $from);
    }
    /**
     * Get the "WHERE" clause of the AS query
     */
    public function _getWhere()
    {
        if (isset($this->scMain['filters'])) {
            foreach ($this->scMain['filters'] as $filter) {
                $where[] = $this->_getFilter($this->scMain['tb_alias'], $filter);
            }
        }
        if (isset($this->scMain['jfilters'])) {
            foreach ($this->scMain['jfilters'] as $filter) {
                $where[] = $this->_getFilter($filter['tb_alias'], $filter);
            }
        }
        return count($where) > 0 ? '(' . implode(' AND ', $where) . ')' : '1';
    }

    /**
     * Get the "GROUP BY" clause of the AS query
     */
    public function _getGroupBy()
    {
        return $this->scMain['tb_alias'] . '.' . $this->scMain['id'];
    }

    /**
     * Get the "HAVING" clause of the AS query
     */
    public function _getHaving($searchString, $advSearch, $fClause)
    {
        $havingClause_array = array();
        if ($searchString != '') {
            $like = $this->_getWhereForm($advSearch);
            $whereOper = $this->_getWhereOper($advSearch);
            $whereStringOper = $this->_getWhereStringOper($advSearch);
            if (isset($this->scMain['searchable'])) {
                foreach ($this->scMain['searchable'] as $searchable) {
                    $hvg[] = '(' . $this->scMain['tb_alias'] . '.' . $searchable . $like . ')';
                }
            }
            if ($advSearch != NOWORDS) {
                if (isset($this->scJoined)) {
                    foreach ($this->scJoined as $joined) {
                        $jpref = $joined['tb_alias'];
                        if (isset($joined['searchable'])) {
                            foreach ($joined['searchable'] as $searchable) {
                                $hvg[] = '(' . $jpref . '_' . $searchable . $like . ')';
                            }
                        }
                    }
                }
                if (isset($this->scTvs['tvs'])) {
                    foreach ($this->scTvs['tvs'] as $scTv) {
                        $jpref = $scTv['tb_alias'];
                        $hvg[] = '(`' . $scTv['name'] . '`' . $like . ')';
                    }
                }
            } else {
                if (isset($this->scJoined)) foreach ($this->scJoined as $joined) {
                    $jpref = $joined['tb_alias'];
                    if (isset($joined['searchable'])) foreach ($joined['searchable'] as $searchable) {
                        $hvg[] = '((' . $jpref . '_' . $searchable . $like . ') OR (' . $jpref . '_' . $searchable . ' IS NULL))';
                    }
                }
                if (isset($this->scTvs['tvs'])) foreach ($this->scTvs['tvs'] as $scTv) {
                    $jpref = $scTv['tb_alias'];
                    $hvg[] = '((`' . $scTv['name'] . '`' . $like . ') OR (`' . $scTv['name'] . '` IS NULL))';
                }
            }
            if (count($hvg) > 0) {
                $havingSubClause = '(' . implode($whereOper, $hvg) . ')';

                $search = $this->_getSearchTerms($searchString, $advSearch);
                foreach ($search as $searchTerm) {
                    $_having = array();
                    $_having[0] = preg_replace('/word/', $searchTerm, $havingSubClause);
                    $namedSearchTerm = $this->_getNamedSearchTerm($searchTerm);
                    if ($namedSearchTerm != $searchTerm) {
                        $_having[1] = preg_replace('/word/', $namedSearchTerm, $havingSubClause);
                        $having[] = '(' . implode(' OR ', $_having) . ')';
                    } else {
                        $having[] = $_having[0];
                    }
                }
                $havingClause = '(' . implode($whereStringOper, $having) . ')';
            }
            $havingClause_array[] = $havingClause;
        }

        if ($fClause) {
            $havingClause_array[] = '(' . $fClause . ')';
        }
        $havingClause = implode(' AND ',$havingClause_array);
        $havingClause = ($havingClause) ? $havingClause : '1';
        return $havingClause;
    }

    /**
     * Get the "GROUP BY" clause of the AS query
     */
    public function _getOrderBy()
    {
        if (isset($this->scCateg)) {
            $orderFields[] = 'category ASC';
        }
        if ($this->cfg['order']) {
            $order = array_map('trim', explode(',', $this->cfg['order']));
            foreach ($order as $ord) {
                $ordElt = explode(' ',$ord);
                $ordby = '`' . $ordElt[0] . '`';
                if (isset($ordElt[1]) && ($ordElt[1] == 'ASC' || $ordElt[1] == 'DESC')) {
                    $ordby .= ' ' . $ordElt[1];
                }
                $orderBy[] = $ordby;
            }
            $orderFields[] = implode(',', $orderBy);
        }
        return count($orderFields) > 0 ? implode(', ', $orderFields) : '1';
    }
    /**
     * Get select statement for a joined table
     */
    public function _getSubSelect($joined, $searchString, $advSearch)
    {
        $fields = array();
        $from = array();
        $where = array();
        $whl = array();

        $fields[] = $joined['tb_alias'] . '.' . $joined['id'];

        if (isset($joined['displayed'])) {
            foreach ($joined['displayed'] as $displayed) {
                $fields[] = $joined['tb_alias'] . '.' . $displayed;
            }
        }

        if ($joined['join'] != $joined['id']) {
            $fields[] = $joined['tb_alias'] . '.' . $joined['join'];
        }
        $fieldsClause = implode(', ', $fields);

        $from[] = $joined['tb_name'] . ' ' . $joined['tb_alias'];

        if (isset($joined['jfilters'])) {
            foreach ($joined['jfilters'] as $jfilter) {
                $f = 'INNER JOIN ' . $jfilter['tb_name'] . ' ' . $jfilter['tb_alias'];
                $f.= ' ON ' . $joined['tb_alias'] . '.' . $jfilter['main'] . ' = ' . $jfilter['tb_alias'] . '.' . $jfilter['join'];
                $from[] = $f;
            }
        }
        $fromClause = implode(' ', $from);

        if (isset($joined['filters'])) {
            foreach ($joined['filters'] as $filter) {
                $where[] = $this->_getFilter($joined['tb_alias'], $filter);
            }
        }
        if (isset($joined['jfilters'])) {
            foreach ($joined['jfilters'] as $jfilter) {
                $where[] = $this->_getFilter($jfilter['tb_alias'], $jfilter);
            }
        }
        $total = count($where);
        if ($total > 0) {
            for ($i = 0; $i < $total; $i++) {
                $where[$i] = '(' . $where[$i] . ')';
            }
            $whl[] = implode(' AND ', $where);
        }

        if ($joined['tb_alias'] != 'tv') {
            if ($searchString) {
                $stw = $this->_getSearchTermsWhere($joined,$searchString,$advSearch);
                if (!empty($stw)) {
                    $whl[] = '(' . $stw . ')';
                }
            }
            if (count($whl)) {
                $whereClause = '(' . implode(' AND ',$whl). ')';
                $subSelect = 'SELECT DISTINCT ' . $fieldsClause . ' FROM ' . $fromClause . ' WHERE ' . $whereClause;
            } else {
                $subSelect = 'SELECT DISTINCT ' . $fieldsClause . ' FROM ' . $fromClause;
            }
        } else {
            $subSelect = 'SELECT DISTINCT ' . $fieldsClause . ' FROM ' . $fromClause;
        }
        return $subSelect;
    }

    /**
     * @param $alias
     * @param $filter
     * @return string
     */
    public function _getFilter($alias, $filter)
    {
        $where = $this->_getSubFilter($alias, $filter);
        if (isset($filter['or'])) {
            $or = $filter['or'];
            $alias = isset($or['tb_alias']) ? $or['tb_alias'] : $this->scMain['tb_alias'];
            $where = '(' . $where . ' OR ' . $this->_getFilter($alias, $or) . ')';
        }
        return $where;
    }

    /**
     * @param $alias
     * @param $filter
     * @return string
     */
    public function _getSubFilter($alias, $filter)
    {
        $where = '';
        if ($filter['oper'] == '=' || $filter['oper'] == 'EQUAL') {
            $where.= $alias . '.' . $filter['field'] . '=' . $filter['value'];
        } elseif ($filter['oper'] == '>' || $filter['oper'] == 'GREAT THAN') {
            $where.= $alias . '.' . $filter['field'] . '>' . $filter['value'];
        } elseif ($filter['oper'] == '<' || $filter['oper'] == 'LESS THAN') {
            $where.= $alias . '.' . $filter['field'] . '<' . $filter['value'];
        } elseif ($filter['oper'] == 'in' || $filter['oper'] == 'IN') {
            $where.= $alias . '.' . $filter['field'] . ' IN (' . $filter['value'] . ')';
        } elseif ($filter['oper'] == 'not in' || $filter['oper'] == 'NOT IN') {
            $where.= $alias . '.' . $filter['field'] . ' NOT IN (' . $filter['value'] . ')';
        }

        if ($where != '') {
            $where = '(' . $where . ')';
        }

        return $where;
    }

    /**
     * @param $joined
     * @param $searchString
     * @param $advSearch
     * @return string
     */
    public function _getSearchTermsWhere($joined, $searchString, $advSearch)
    {
        $whereClause = '';
        if (!empty($joined['searchable'])) {
            $like = $this->_getWhereForm($advSearch);
            $whereOper = $this->_getWhereOper($advSearch);
            $type = ($advSearch == 'allwords') ? 'oneword' : $advSearch;
            $whereStringOper = $this->_getWhereStringOper($type);

            foreach ($joined['searchable'] as $searchable) {
                $whsc[] = '(' . $joined['tb_alias'] . '.' . $searchable . $like .')';
            }
            if (count($whsc)) {
                $whereSubClause = implode($whereOper, $whsc);

                $search = array();
                if ($advSearch === 'exactphrase') {
                    $search[] = $searchString;
                } else {
                    $search = explode(' ', $searchString);
                }

                foreach ($search as $searchTerm) {
                    $where[] = preg_replace('/word/', preg_quote($searchTerm, '/'), $whereSubClause);
                }

                $whereClause = implode($whereStringOper,$where);
            }
        }
        return $whereClause;
    }

    /**
     * @param $advSearch
     * @return string
     */
    public function _getWhereForm($advSearch)
    {
        $whereForm = array('like' => " LIKE '%word%'", 'notlike' => " NOT LIKE '%word%'", 'regexp' => " REGEXP '[[:<:]]word[[:>:]]'");
        if ($advSearch == NOWORDS) {
            return $whereForm['notlike'];
        } elseif ($advSearch == EXACTPHRASE) {
            return $whereForm['regexp'];
        } else {
            return $whereForm['like'];
        }
    }

    /**
     * @param $advSearch
     * @return mixed
     */
    public function _getWhereOper($advSearch)
    {
        $whereOper = array('or' => " OR ", 'and' => " AND ");
        return $advSearch == NOWORDS ? $whereOper['and'] : $whereOper['or'];
    }

    /**
     * @param $advSearch
     * @return string
     */
    public function _getWhereStringOper($advSearch)
    {
        $whereStringOper = array('or' => " OR ", 'and' => " AND ");
        return ($advSearch == NOWORDS || $advSearch == ALLWORDS) ? $whereStringOper['and'] : $whereStringOper['or'];
    }

    /**
     * @param $searchString
     * @param $advSearch
     * @return array
     */
    public function _getSearchTerms($searchString, $advSearch)
    {
        if ($advSearch === EXACTPHRASE) {
            $search[] = $searchString;
        } else {
            $search = explode(' ', $searchString);
        }
        return $search;
    }

    /**
     * Get named html entities version of the search term.
     *
     * @return string
     */
    public function _getNamedSearchTerm($searchString)
    {
        return $this->_htmlentities($searchString, ENT_COMPAT, $this->pgCharset, false);
    }

    /**
     * Get the subselect query related to the Tvs
     */
    public function _getTvSubSelect($tvs_array,$name,$abrev,$mode)
    {
        global $modx;
        $scTvs = array();

        if ($mode === 'simple') {
            $i = 1;
            foreach ($tvs_array as $tv) {
                $rs = $modx->db->select("DISTINCT id", $this->_getShortTableName('site_tmplvars'), "name='{$tv}'");
                $id = $modx->db->getValue($rs);

                $alias = $abrev . $i;
                $nm = ($name) ? $name : $tv;
                $subselect = "SELECT DISTINCT ".$alias.".contentid , ".$alias.".value ";
                $subselect.= "FROM " . $this->_getShortTableName('site_tmplvar_contentvalues') . " ".$alias." ";
                $subselect.= "WHERE ".$alias.".tmplvarid = '{$id}'";

                $scTvs[] = array(
                    'tb_alias' => 'n'.$alias,
                    'main' => 'id',
                    'join' => 'contentid',
                    'displayed' => 'value',
                    'searchable' => 'value',
                    'sql' => $subselect,
                    'name' => $nm
                );
                $i++;
            }
        } else { // mode = concat
            $lstTvs = "'" . implode("','",$tvs_array) . "'";
            $rs = $modx->db->select(
                "GROUP_CONCAT( DISTINCT CAST(id AS CHAR) SEPARATOR \",\" ) AS ids",
                $this->_getShortTableName('site_tmplvars'),
                "name in ({$lstTvs})"
            );
            $ids = $modx->db->getValue($rs);

            $subselect = "SELECT DISTINCT " . $abrev . ".contentid , " . $abrev . ".value ";
            $subselect.= "FROM " . $this->_getShortTableName('site_tmplvar_contentvalues') . " " . $abrev . " ";
            $subselect.= "WHERE " . $abrev . ".tmplvarid in (" . $ids . ")";

            $scTvs[] = array(
                'tb_alias' => 'n'.$abrev,
                'main' => 'id',
                'join' => 'contentid',
                'displayed' => 'value',
                'searchable' => 'value',
                'sql' => $subselect,
                'name' => $name
            );
        }
        return $scTvs;
    }

    /**
     * Return the array of tv
     *
     * @param $ltv
     * @return array
     */
    public function _getTvsArray($ltv)
    {
        $tvs_array = explode(':',$ltv);
        $tvSign = $tvs_array[0];
        if (($tvSign !== '+') && ($tvSign !== '-')) {
            $tvList = $tvSign;
            $tvSign = '+';
        } else {
            $tvList = isset($tvs_array[1]) ? $tvs_array[1] : '';
        }

        if ($tvSign === '+') {
            $tvs_array = $tvList ? explode(',',$tvList) : $this->_getSiteTvs();
        } else {
            $allTvs_array = $this->_getSiteTvs();
            $minusTvs_array = explode(',',$tvList);
            $tvs_array = array_diff($allTvs_array, $minusTvs_array);
        }
        return $tvs_array;
    }

    /**
     * Return the list of TVs of a site
     */
    public function _getSiteTvs()
    {
        global $modx;
        $tvs_array = array();
        $tblName = $modx->getFullTableName('site_tmplvars');
        $rs = $modx->db->select("GROUP_CONCAT( DISTINCT name SEPARATOR ',' ) AS list", $tblName, "type='text'");
        $list = $modx->db->getValue($rs);
        if ($list) {
            $tvs_array = explode(',',$list);
        }
        return $tvs_array;
    }

    /**
     * Check the validity of a value separated list of Ids
     */
    public function _validListIDs($ids)
    {
        $groups = explode(',', $ids);
        $nbg = count($groups);
        for ($i = 0;$i < $nbg;$i++) {
            if (preg_match('/^[0-9]+$/', $groups[$i]) == 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Append the search results with the TVs fields
     *
     * @return array
     */
    public function _appendTvs($rs)
    {
        global $modx;

        $records = array();

        if (!$this->cfg['tvPhx']) {
            $records = $modx->db->makeArray($rs);
        } else {
            $tvNames_array = isset($this->cfg['withTvs']) && ($this->cfg['withTvs']) ?
                array_diff($this->_getTvsArray($this->cfg['tvPhx']), $this->scTvs['names']) :
                $this->_getTvsArray($this->cfg['tvPhx']);
            $tvNames_array = array_unique($tvNames_array);

            if ($this->dbg) {
                $this->asUtil->dbgRecord($tvNames_array, "AppendTvs - tvPhx");
            }

            $i = 0;
            while($row = $modx->db->getRow($rs)){
                $records[] = $row;
                $tv_array = $this->_getDocTvs($row['id'],$tvNames_array);
                foreach ($tv_array as $name => $output) {
                    $records[$i][$name] = $output;
                    $this->scMain['append'][] = $name;
                }
                $i++;
            }
        }
        return $records;
    }

    /**
     * Get the user defined tvs of a document
     */
    public function _getDocTvs($docid, $tvNames_array)
    {
        global $modx;
        $results = array();

        $where = count($tvNames_array) ? " AND name in ('" . implode("','",$tvNames_array) . "')" : '';

        $rs= $modx->db->select(
            "DISTINCT tv.id AS id",
            $modx->getFullTableName('site_tmplvars')." AS tv 
				INNER JOIN " . $modx->getFullTableName('site_tmplvar_templates')." AS tvtpl ON tvtpl.tmplvarid = tv.id
				LEFT JOIN " . $modx->getFullTableName('site_tmplvar_contentvalues')." AS tvc ON tvc.tmplvarid=tv.id AND tvc.contentid = '{$docid}'",
            "tvc.contentid = '{$docid}' {$where}"
        );
        $idnames = $modx->db->getColumn('id', $rs);
        if ($idnames) {
            $results = $modx->getTemplateVarOutput($idnames,$docid);
            if (!$results) {
                $results = array();
            }
        }
        return $results;
    }

    /**
     * Returns a short table name based on db settings
     */
    public function _getShortTableName($tbl)
    {
        global $modx;
        return '`' . $modx->db->config['table_prefix'] . $tbl . '`';
    }

    /**
     * print Select
     */
    public function _printSelect($query)
    {
        $searched = array(
            " SELECT",
            " GROUP_CONCAT",
            " LEFT JOIN",
            " INNER JOIN",
            " SELECT",
            " FROM",
            " WHERE",
            " GROUP BY",
            " HAVING",
            " ORDER BY"
        );
        $replace = array(
            " \r\nSELECT",
            " \r\nGROUP_CONCAT",
            " \r\nLEFT JOIN",
            " \r\nINNER JOIN",
            " \r\nSELECT",
            " \r\nFROM",
            " \r\nWHERE",
            " \r\nGROUP BY",
            " \r\nHAVING",
            " \r\nORDER BY"
        );
        $query = str_replace($searched, $replace, ' ' . $query);
        return $query;
    }

    /**
     * htmlentities
     */
    public function _htmlentities($string, $quote_style = ENT_COMPAT, $charset = 'UTF-8', $double_encode = false)
    {
        if (version_compare(PHP_VERSION, '5.2.3', '>=')) {
            $string = htmlentities($string, $quote_style, $charset, $double_encode);
        } else {
            $string = htmlentities($string, $quote_style, $charset);
        }
        return $string;
    }
}
