<?php

namespace Illuminate\Database\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Grammar as BaseGrammar;

class Grammar extends BaseGrammar
{
    /**
     * The grammar specific operators.
     * 语法操作数组
     *
     * @var array
     */
    protected $operators = [];

    /**
     * The components that make up a select clause.
     * 组成查询语句的组成部分
     *
     * @var array
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'unions',
        'lock',
    ];

    /**
     * Compile a select query into SQL.
     * 将查询语句编译成SQL语句
     * 1.获取查询字段,未设置则为*
     * 2.根据查询字段生成sql
     * 3.将查询字段还原
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public function compileSelect(Builder $query)
    {
        $original = $query->columns;

        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        $sql = trim($this->concatenate($this->compileComponents($query)));

        $query->columns = $original;

        return $sql;
    }

    /**
     * Compile the components necessary for a select clause.
     * 编译查询语句的必要组件
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array
     */
    protected function compileComponents(Builder $query)
    {
        $sql = [];

        foreach ($this->selectComponents as $component) {
            // To compile the query, we'll spin through each component of the query and
            // see if that component exists. If it does we'll just call the compiler
            // function for the component which is responsible for making the SQL.
            // 查看每一个查询组件是否存在,需要为每个组件调用相关的编译方法生成SQL
            if (! is_null($query->$component)) {
                $method = 'compile'.ucfirst($component);
                // var_dump($method);

                $sql[$component] = $this->$method($query, $query->$component);
            }
        }

        return $sql;
    }

    /**
     * Compile an aggregated select clause.
     * 编译一个聚合的查询语句
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $aggregate
     * @return string
     */
    protected function compileAggregate(Builder $query, $aggregate)
    {
        $column = $this->columnize($aggregate['columns']);

        // If the query has a "distinct" constraint and we're not asking for all columns
        // we need to prepend "distinct" onto the column name so that the query takes
        // it into account when it performs the aggregating operations on the data.
        // 如果查询中存在distinct限制,我们则不需要请求所有记录,将预先distinct装进字段名中,这样数据查询就能进行聚合操作.
        if ($query->distinct && $column !== '*') {
            $column = 'distinct '.$column;
        }

        return 'select '.$aggregate['function'].'('.$column.') as aggregate';
    }

    /**
     * Compile the "select *" portion of the query.
     * 编译select *这部分的查询
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $columns
     * @return string|null
     */
    protected function compileColumns(Builder $query, $columns)
    {
        // If the query is actually performing an aggregating select, we will let that
        // compiler handle the building of the select clauses, as it will need some
        // more syntax that is best handled by that function to keep things neat.
        // 如果查询完成了聚合查询,我们将使用聚合编译句柄来生成查询语句
        if (! is_null($query->aggregate)) {
            return;
        }

        $select = $query->distinct ? 'select distinct ' : 'select ';

        return $select.$this->columnize($columns);
    }

    /**
     * Compile the "from" portion of the query.
     * 编译查询的from部分
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $table
     * @return string
     */
    protected function compileFrom(Builder $query, $table)
    {
        return 'from '.$this->wrapTable($table);
    }

    /**
     * Compile the "join" portions of the query.
     * 编译查询的join部分
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $joins
     * @return string
     */
    protected function compileJoins(Builder $query, $joins)
    {
        $sql = [];

        foreach ($joins as $join) {
            $table = $this->wrapTable($join->table);

            $type = $join->type;

            // Cross joins generate a cartesian product between this first table and a joined
            // table. In case the user didn't specify any "on" clauses on the join we will
            // append this SQL and jump right back into the next iteration of this loop.
            // cross联合生成第一个表和关联表的笛卡尔集合,为了防止用户没有写on语句,我们将增加这个sql,然后跳出循环
            if ($type === 'cross' &&  ! $join->clauses) {
                $sql[] = "cross join $table";

                continue;
            }

            // First we need to build all of the "on" clauses for the join. There may be many
            // of these clauses so we will need to iterate through each one and build them
            // separately, then we'll join them up into a single string when we're done.
            // 我们为关联构造所有的on语句,可能会有很多的on语句,我们需要迭代每个然后独立生成它,最后将它们关联成一个单独的字符串
            $clauses = [];

            foreach ($join->clauses as $clause) {
                $clauses[] = $this->compileJoinConstraint($clause);
            }

            // Once we have constructed the clauses, we'll need to take the boolean connector
            // off of the first clause as it obviously will not be required on that clause
            // because it leads the rest of the clauses, thus not requiring any boolean.
            // ????????
            $clauses[0] = $this->removeLeadingBoolean($clauses[0]);

            $clauses = implode(' ', $clauses);

            // Once we have everything ready to go, we will just concatenate all the parts to
            // build the final join statement SQL for the query and we can then return the
            // final clause back to the callers as a single, stringified join statement.
            $sql[] = "$type join $table on $clauses";
        }

        return implode(' ', $sql);
    }

    /**
     * Create a join clause constraint segment.
     * 创建一个关联语句限制部分(and id = ?或者or id not in (?,?,?))
     *
     * @param  array  $clause
     * @return string
     */
    protected function compileJoinConstraint(array $clause)
    {
        if ($clause['nested']) {
            return $this->compileNestedJoinConstraint($clause);
        }

        $first = $this->wrap($clause['first']);

        if ($clause['where']) {
            if ($clause['operator'] === 'in' || $clause['operator'] === 'not in') {
                $second = '('.implode(', ', array_fill(0, $clause['second'], '?')).')';
            } else {
                $second = '?';
            }
        } else {
            $second = $this->wrap($clause['second']);
        }

        return "{$clause['boolean']} $first {$clause['operator']} $second";
    }

    /**
     * Create a nested join clause constraint segment.
     * 创建嵌套关联语句
     *
     * @param  array  $clause
     * @return string
     */
    protected function compileNestedJoinConstraint(array $clause)
    {
        $clauses = [];

        foreach ($clause['join']->clauses as $nestedClause) {
            $clauses[] = $this->compileJoinConstraint($nestedClause);
        }

        $clauses[0] = $this->removeLeadingBoolean($clauses[0]);

        $clauses = implode(' ', $clauses);

        return "{$clause['boolean']} ({$clauses})";
    }

    /**
     * Compile the "where" portions of the query.
     * 编译查询的where部分
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileWheres(Builder $query)
    {
        $sql = [];

        if (is_null($query->wheres)) {
            return '';
        }

        // Each type of where clauses has its own compiler function which is responsible
        // for actually creating the where clauses SQL. This helps keep the code nice
        // and maintainable since each clause has a very small method that it uses.
        foreach ($query->wheres as $where) {
            $method = "where{$where['type']}";

            $sql[] = $where['boolean'].' '.$this->$method($query, $where);
        }

        // If we actually have some where clauses, we will strip off the first boolean
        // operator, which is added by the query builders for convenience so we can
        // avoid checking for the first clauses in each of the compilers methods.
        // 有很多where语句,我们将删除第一个逻辑关键词(and | or)
        if (count($sql) > 0) {
            $sql = implode(' ', $sql);

            return 'where '.$this->removeLeadingBoolean($sql);
        }

        return '';
    }

    /**
     * Compile a nested where clause.
     * 编译嵌套的where语句
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNested(Builder $query, $where)
    {
        $nested = $where['query'];

        return '('.substr($this->compileWheres($nested), 6).')';
    }

    /**
     * Compile a where condition with a sub-select
     * 用子查询编译一个where条件(id in (select ...))
     *
     * @param  \Illuminate\Database\Query\Builder $query
     * @param  array   $where
     * @return string
     */
    protected function whereSub(Builder $query, $where)
    {
        $select = $this->compileSelect($where['query']);

        return $this->wrap($where['column']).' '.$where['operator']." ($select)";
    }

    /**
     * Compile a basic where clause.
     * 编译一个基本的where语句(id = 1)
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereBasic(Builder $query, $where)
    {
        $value = $this->parameter($where['value']);

        return $this->wrap($where['column']).' '.$where['operator'].' '.$value;
    }

    /**
     * Compile a where clause comparing two columns..
     * 编译where语句比较两列值
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereColumn(Builder $query, $where)
    {
        $second = $this->wrap($where['second']);

        return $this->wrap($where['first']).' '.$where['operator'].' '.$second;
    }

    /**
     * Compile a "between" where clause.
     * 编译where区间语句
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereBetween(Builder $query, $where)
    {
        $between = $where['not'] ? 'not between' : 'between';

        return $this->wrap($where['column']).' '.$between.' ? and ?';
    }

    /**
     * Compile a where exists clause.
     * 编译exists语句
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereExists(Builder $query, $where)
    {
        return 'exists ('.$this->compileSelect($where['query']).')';
    }

    /**
     * Compile a where exists clause.
     * 编译not exists语句
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotExists(Builder $query, $where)
    {
        return 'not exists ('.$this->compileSelect($where['query']).')';
    }

    /**
     * Compile a "where in" clause.
     * 编译in语句
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereIn(Builder $query, $where)
    {
        if (empty($where['values'])) {
            return '0 = 1';
        }

        $values = $this->parameterize($where['values']);

        return $this->wrap($where['column']).' in ('.$values.')';
    }

    /**
     * Compile a "where not in" clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotIn(Builder $query, $where)
    {
        if (empty($where['values'])) {
            return '1 = 1';
        }

        $values = $this->parameterize($where['values']);

        return $this->wrap($where['column']).' not in ('.$values.')';
    }

    /**
     * Compile a where in sub-select clause.
     * 用子查询语句编译where语句
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereInSub(Builder $query, $where)
    {
        $select = $this->compileSelect($where['query']);

        return $this->wrap($where['column']).' in ('.$select.')';
    }

    /**
     * Compile a where not in sub-select clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotInSub(Builder $query, $where)
    {
        $select = $this->compileSelect($where['query']);

        return $this->wrap($where['column']).' not in ('.$select.')';
    }

    /**
     * Compile a "where null" clause.
     * 编译null语句
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNull(Builder $query, $where)
    {
        return $this->wrap($where['column']).' is null';
    }

    /**
     * Compile a "where not null" clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNotNull(Builder $query, $where)
    {
        return $this->wrap($where['column']).' is not null';
    }

    /**
     * Compile a "where date" clause.
     * ????这个是什么语句
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereDate(Builder $query, $where)
    {
        return $this->dateBasedWhere('date', $query, $where);
    }

    /**
     * Compile a "where day" clause.
     * 没用到
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereDay(Builder $query, $where)
    {
        return $this->dateBasedWhere('day', $query, $where);
    }

    /**
     * Compile a "where month" clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereMonth(Builder $query, $where)
    {
        return $this->dateBasedWhere('month', $query, $where);
    }

    /**
     * Compile a "where year" clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereYear(Builder $query, $where)
    {
        return $this->dateBasedWhere('year', $query, $where);
    }

    /**
     * Compile a date based where clause.
     * 编译基于日期的语句
     *
     * @param  string  $type
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function dateBasedWhere($type, Builder $query, $where)
    {
        $value = $this->parameter($where['value']);

        return $type.'('.$this->wrap($where['column']).') '.$where['operator'].' '.$value;
    }

    /**
     * Compile a raw where clause.
     * 编译原生的sql语句
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereRaw(Builder $query, $where)
    {
        return $where['sql'];
    }

    /**
     * Compile the "group by" portions of the query.
     * 编译查询group by部分
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $groups
     * @return string
     */
    protected function compileGroups(Builder $query, $groups)
    {
        return 'group by '.$this->columnize($groups);
    }

    /**
     * Compile the "having" portions of the query.
     *
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $havings
     * @return string
     */
    protected function compileHavings(Builder $query, $havings)
    {
        $sql = implode(' ', array_map([$this, 'compileHaving'], $havings));

        return 'having '.$this->removeLeadingBoolean($sql);
    }

    /**
     * Compile a single having clause.
     *
     * @param  array   $having
     * @return string
     */
    protected function compileHaving(array $having)
    {
        // If the having clause is "raw", we can just return the clause straight away
        // without doing any more processing on it. Otherwise, we will compile the
        // clause into SQL based on the components that make it up from builder.
        if ($having['type'] === 'raw') {
            return $having['boolean'].' '.$having['sql'];
        }

        return $this->compileBasicHaving($having);
    }

    /**
     * Compile a basic having clause.
     *
     * @param  array   $having
     * @return string
     */
    protected function compileBasicHaving($having)
    {
        $column = $this->wrap($having['column']);

        $parameter = $this->parameter($having['value']);

        return $having['boolean'].' '.$column.' '.$having['operator'].' '.$parameter;
    }

    /**
     * Compile the "order by" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $orders
     * @return string
     */
    protected function compileOrders(Builder $query, $orders)
    {
        return 'order by '.implode(', ', array_map(function ($order) {
            if (isset($order['sql'])) {
                return $order['sql'];
            }

            return $this->wrap($order['column']).' '.$order['direction'];
        }, $orders));
    }

    /**
     * Compile the random statement into SQL.
     *
     * @param  string  $seed
     * @return string
     */
    public function compileRandom($seed)
    {
        return 'RANDOM()';
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  int  $limit
     * @return string
     */
    protected function compileLimit(Builder $query, $limit)
    {
        return 'limit '.(int) $limit;
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  int  $offset
     * @return string
     */
    protected function compileOffset(Builder $query, $offset)
    {
        return 'offset '.(int) $offset;
    }

    /**
     * Compile the "union" queries attached to the main query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileUnions(Builder $query)
    {
        $sql = '';

        foreach ($query->unions as $union) {
            $sql .= $this->compileUnion($union);
        }

        if (isset($query->unionOrders)) {
            $sql .= ' '.$this->compileOrders($query, $query->unionOrders);
        }

        if (isset($query->unionLimit)) {
            $sql .= ' '.$this->compileLimit($query, $query->unionLimit);
        }

        if (isset($query->unionOffset)) {
            $sql .= ' '.$this->compileOffset($query, $query->unionOffset);
        }

        return ltrim($sql);
    }

    /**
     * Compile a single union statement.
     *
     * @param  array  $union
     * @return string
     */
    protected function compileUnion(array $union)
    {
        $joiner = $union['all'] ? ' union all ' : ' union ';

        return $joiner.$union['query']->toSql();
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return string
     */
    public function compileExists(Builder $query)
    {
        $select = $this->compileSelect($query);

        return "select exists($select) as {$this->wrap('exists')}";
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileInsert(Builder $query, array $values)
    {
        // Essentially we will force every insert to be treated as a batch insert which
        // simply makes creating the SQL easier for us since we can utilize the same
        // basic routine regardless of an amount of records given to us to insert.
        $table = $this->wrapTable($query->from);

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        $columns = $this->columnize(array_keys(reset($values)));

        // We need to build a list of parameter place-holders of values that are bound
        // to the query. Each insert should have the exact same amount of parameter
        // bindings so we will loop through the record and parameterize them all.
        $parameters = [];

        foreach ($values as $record) {
            $parameters[] = '('.$this->parameterize($record).')';
        }

        $parameters = implode(', ', $parameters);

        return "insert into $table ($columns) values $parameters";
    }

    /**
     * Compile an insert and get ID statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array   $values
     * @param  string  $sequence
     * @return string
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        return $this->compileInsert($query, $values);
    }

    /**
     * Compile an update statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileUpdate(Builder $query, $values)
    {
        $table = $this->wrapTable($query->from);

        // Each one of the columns in the update statements needs to be wrapped in the
        // keyword identifiers, also a place-holder needs to be created for each of
        // the values in the list of bindings so we can make the sets statements.
        $columns = [];

        foreach ($values as $key => $value) {
            $columns[] = $this->wrap($key).' = '.$this->parameter($value);
        }

        $columns = implode(', ', $columns);

        // If the query has any "join" clauses, we will setup the joins on the builder
        // and compile them so we can attach them to this update, as update queries
        // can get join statements to attach to other tables when they're needed.
        if (isset($query->joins)) {
            $joins = ' '.$this->compileJoins($query, $query->joins);
        } else {
            $joins = '';
        }

        // Of course, update queries may also be constrained by where clauses so we'll
        // need to compile the where clauses and attach it to the query so only the
        // intended records are updated by the SQL statements we generate to run.
        $where = $this->compileWheres($query);

        return trim("update {$table}{$joins} set $columns $where");
    }

    /**
     * Prepare the bindings for an update statement.
     *
     * @param  array  $bindings
     * @param  array  $values
     * @return array
     */
    public function prepareBindingsForUpdate(array $bindings, array $values)
    {
        return $bindings;
    }

    /**
     * Compile a delete statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public function compileDelete(Builder $query)
    {
        $table = $this->wrapTable($query->from);

        $where = is_array($query->wheres) ? $this->compileWheres($query) : '';

        return trim("delete from $table ".$where);
    }

    /**
     * Compile a truncate table statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array
     */
    public function compileTruncate(Builder $query)
    {
        return ['truncate '.$this->wrapTable($query->from) => []];
    }

    /**
     * Compile the lock into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  bool|string  $value
     * @return string
     */
    protected function compileLock(Builder $query, $value)
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Determine if the grammar supports savepoints.
     *
     * @return bool
     */
    public function supportsSavepoints()
    {
        return true;
    }

    /**
     * Compile the SQL statement to define a savepoint.
     *
     * @param  string  $name
     * @return string
     */
    public function compileSavepoint($name)
    {
        return 'SAVEPOINT '.$name;
    }

    /**
     * Compile the SQL statement to execute a savepoint rollback.
     *
     * @param  string  $name
     * @return string
     */
    public function compileSavepointRollBack($name)
    {
        return 'ROLLBACK TO SAVEPOINT '.$name;
    }

    /**
     * Concatenate an array of segments, removing empties.
     * 过滤数组中的空格,然后拼接成字符串
     *
     * @param  array   $segments
     * @return string
     */
    protected function concatenate($segments)
    {
        return implode(' ', array_filter($segments, function ($value) {
            return (string) $value !== '';
        }));
    }

    /**
     * Remove the leading boolean from a statement.
     * 移除关联条件中第一个and或者or关键词
     *
     * @param  string  $value
     * @return string
     */
    protected function removeLeadingBoolean($value)
    {
        return preg_replace('/and |or /i', '', $value, 1);
    }

    /**
     * Get the gramar specific operators.
     *
     * @return array
     */
    public function getOperators()
    {
        return $this->operators;
    }
}
