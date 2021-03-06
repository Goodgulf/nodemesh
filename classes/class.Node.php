<?
/*
 *  Copyright 2010 Enleap, LLC
 *
 *  This file is part of the Node Mesh.
 *
 *  The Node Mesh is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The Node Mesh is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with the Node Mesh.  If not, see <http://www.gnu.org/licenses/>.
 */

class Node extends NodeCore
{
    private $_attributes;

    public function __construct($type)
    {
        // For virtual nodes add the negative where clause
        $options = func_get_args();

        if (count($options) < 2)
        {
            $options[] = 'virtual';
        }

        // Pass everything onto the parent for processing
        @call_user_func_array(array('parent', '__construct'), $options);
    }

    protected function _fetch()
    {
        $query  = new Query($this->_callChain);
//        echo '<p>';
//        print_r($query);
        try
        {
            $data = $query->execute();
            if (! empty($data))
            {
                $this->_cache->populate($data[0]);
            }
        }
        catch (Exception $e)
        {
            throw $e;
        }

        return;
    }

    public function toArray()
    {
        $data = $this->_cache->getData();

//        print_r($data);

        if (empty($data))
        {
            $query = new Query($this->_callChain);
            try
            {
                $data = $query->execute();
                if (! empty($data))
                {
                    $this->_cache->populate($data[0]);
                }
                $data = $this->_cache->getData();
            }
            catch (Exception $e)
            {
                throw $e;
            }
        }

        return $data;
    }

    public function Me()
    {
    	$params = func_get_args();

    	if (! empty($params))
    	{
    		throw new Exception('A single ' . __CLASS__ . ' cannot be filtered');
    	}

    	return call_user_func_array(array($this, 'parent::Me'), $params);
    }

    /**
     * This method links the node to the passed node or cluster
     *
     * @param $nodes - can be a node, cluster, array of nodes, array of clusters
     * @return unknown_type
     */
    public function unlink($nodes)
    {
        $this->link($nodes, null, '_unlinkNode');
    }

    /**
     * This method links the node to the passed node or cluster
     *
     * @param $nodes - can be a node, cluster, array of nodes, array of clusters
     * @param $link_attributes
     * @return unknown_type
     */
    public function link($nodes, $link_attributes = null, $link_method = '_linkNode')
    {
        // Check for Nodes
        if ($nodes instanceOf Node)
        {
            call_user_func_array(array($this, $link_method), array($nodes, $link_attributes));
        }

        // Check for Clusters
        if ($nodes instanceOf Cluster)
        {
            foreach ($nodes as $node)
            {
                call_user_func_array(array($this, $link_method), array($node, $link_attributes));
            }
        }

        // Check for pks
        if (is_numeric($nodes))
        {
            throw new Exception('Link operations based on pks is not allowed because the node type cannot be determined.');
        }

        // Check for arrays of nodes, pks or clusters
        if (is_array($nodes))
        {
            // If an array of Nodes
            if ($nodes[key($nodes)] instanceOf Node)
            {
                foreach ($nodes as $node)
                {
                    call_user_func_array(array($this, $link_method), array($node, $link_attributes));
                }
            }

            // If an array of Clusters
            if ($nodes[key($nodes)] instanceOf Cluster)
            {
                foreach ($nodes as $cluster)
                {
                    foreach ($cluster as $node)
                    {
                        call_user_func_array(array($this, $link_method), array($node, $link_attributes));
                    }
                }
            }

            // If an array of pks, check value of first node
            if (is_numeric($nodes[key($nodes)]))
            {
                throw new Exception('Link operations based on pks is not allowed because the node type cannot be determined.');
            }
        }
    }


    public function commit(array $attributes)
    {
        //TODO: move all this logic to the query class

        // Get the node type
        $type = $this->getType();

        // Determine the correct context
        if (is_string($attributes['context']))
        {
            // Use the passed context
            $attributes['context'] = MeshTools::GetContextPks($attributes['context']);
        }
        else if (!$attributes['context'])
        {
            if ($this->pk)
            {
                // Bypass context on existing nodes if not explicitly passed in
                unset($attributes['context']);
            }
            else
            {
                // For new nodes use the default context
                $attributes['context'] = (array)MeshTools::GetDefaultContextPk();

                if (empty($attributes['context']))      // for non-context setups
                {
                    unset($attributes['context']);
                }

            }
        }

        if (! isset($attributes['context']))
        {
            // Do nothing, bypass context updates
        }
        else if (1 == count($attributes['context']))
        {
            $attributes['context'] = $attributes['context'][0];
        }
        else        // @todo New nodes can only have one context
        {
            throw new Exception('Cannot create node with ambiguous context');
        }


        // Separate the attirbutes from the links
        foreach ($attributes as $key => $value)
        {
            // Link Nodes if not attributes
            if (! $this->_isAttribute($key))
            {
                // Use only valid node types
                if ($this->_isNodeType($key))
                {
                    // Copy the attribute to the links array
                    $links[$key] = $attributes[$key];
                }
                else
                {
                    throw new Exception('Cannot link '.$type.' with '.$key.' because type '.$key.' doesn\'t exist.');
                }

                // Remove all non-attirbutes from the attributes array
                unset($attributes[$key]);
            }
        }

        try
        {
            // First update/insert the attributes
            // If the node exists then peform update, otherwise insert
            if ($this->pk)
            {
                // cache the data for this node
                $this->_cache->populate($attributes);

                $dbc = new DatabaseConnection();

                $sql = "UPDATE $type SET ";

                foreach ($attributes as $key => $value)
                {
                    $sql .= " $key = '".$dbc->escape($value)."', ";
                }

                $sql    = rtrim($sql, ', ');
                $sql    .= " WHERE pk = $this->pk";

                $dbc->query($sql);
            }
            else
            {
                // cache the data for this node
                $this->_cache->populate($attributes);

                $dbc = new DatabaseConnection();

                // Clean the attributes
                array_walk($attributes, array($this, '_escapeString'));

                // Build the columns and values
                $columns    = implode(',', array_keys($attributes));
                $values     = "'".implode("','", $attributes)."'";
                $sql        = "INSERT INTO $type ($columns) VALUES ($values)";

                $dbc->query($sql);

                $this->_cache->populate(array('pk' => mysql_insert_id()));      // @todo MySQL-dependant!
            }

            // Now link the nodes
            if (count($links))
            {
                foreach ($links as $type => $nodes)
                {
                    $this->_linkNodes($nodes, $type);
                }
            }

            $node = new Node($this->_callChain);

            return $node;
        }
        catch (Exception $e)
        {
            throw new Exception($e);
        }
    }

    private function _isAttribute($attribute)
    {
        // TODO: Move this logic into the query class

        $type   = $this->getType();


        if (empty($this->_attributes))
        {
            $dbc    = new DatabaseConnection();
            $sql    = "SHOW COLUMNS FROM $type ";       // @todo Mysql specific
            $rows   = $dbc->query($sql);

            foreach ($rows as $r)
            {
                $this->_attributes[] = $r['Field'];
            }
        }

        return in_array($attribute, $this->_attributes);
    }

    /**
     * Will link the node(s) to the current Node
     * @param $nodes
     * @return unknown_type
     */
    private function _linkNodes($nodes, $type = null)
    {
        throw new Exception('$node->commit() no longer supports linking operations.  Please use $node->link() instead.');
    }

    private function _linkNode($node, $link_properties = null)
    {
        try
        {
            $dbc = new DatabaseConnection();

            $type1 = $this->getType();
            $type2 = $node->getType();

            if ($type1 < $type2 || $type1 == $type2 && $this->pk < $node->pk)
            {
                $table_name             = "$type1#$type2";
                $link_properties['pk1'] = $this->pk;
                $link_properties['pk2'] = $node->pk;
                $ltr                    = true;
            }
            else
            {
                $table_name             = "$type2#$type1";
                $link_properties['pk1'] = $node->pk;
                $link_properties['pk2'] = $this->pk;
                $ltr                    = false;
            }

            // Modify special 'direction' property
            if (array_key_exists('direction', $link_properties))
            {
                switch ($link_properties['direction'])
                {
                    case 'forward':
                        $link_properties['direction'] = $ltr ? 'LTR' : 'RTL';
                        break;

                    case 'reverse':
                        $link_properties['direction'] = $ltr ? 'RTL' : 'LTR';
                        break;

                    default:
                        throw new Exception("Invalid link direction '" . $link_properties['direction'] . "'");
                        break;
                }
            }

            // Clean the properties
            array_walk($link_properties, array($dbc, 'escape'));

            // Update links if they exist, otherwise make new ones
            if ($this->_linkExists($table_name, $link_properties['pk1'], $link_properties['pk2']))
            {
                // Update the link
                $sql = "UPDATE `$table_name` SET ";

                foreach ($link_properties as $key => $value)
                {
                    $sql .= " $key = '$value', ";
                }

                $sql    = rtrim($sql, ', ');
                $sql    .= " WHERE pk1 = ".$link_properties['pk1']." AND pk2 = ".$link_properties['pk2'];

                $dbc->query($sql);
            }
            else
            {
                // Build the columns and values
                $columns    = implode(',', array_keys($link_properties));
                $values     = "'".implode("','", $link_properties)."'";

                // Insert a new link
                $sql = "INSERT INTO `$table_name` ($columns) VALUES ($values)";
                $dbc->query($sql);
            }

            return true;
        }
        catch (Exception $e)
        {
            throw new Exception("Could not link nodes: $sql, ".$e->getMessage());
        }
    }

    private function _unlinkNode($node)
    {
        // Get the node types
        $node_type1 = $this->getType();
        $node_type2 = $node->getType();

        // Order the name of the table
        if ($node_type1 != $node_type2)
        {
            $arr[$node_type1] = $this->pk;
            $arr[$node_type2] = $node->pk;
            ksort($arr);

            $keys = array_keys($arr);

            $table_prefix   = $keys[0];
            $table_suffix   = $keys[1];

            $pk1 = $arr[$table_prefix];
            $pk2 = $arr[$table_suffix];
        }
        else
        {
            $table_prefix   = $node_type1;
            $table_suffix   = $node_type2;

            if ($this->pk > $node->pk)
            {
                $pk1 = $node->pk;
                $pk2 = $this->pk;
            }
            else
            {
                $pk1 = $this->pk;
                $pk2 = $node->pk;
            }
        }

        $table_name     = "$table_prefix#$table_suffix";

        $dbc    = new DatabaseConnection();
        $sql    = "DELETE FROM `$table_name` WHERE pk1 = $pk1 AND pk2 = $pk2";
        $rows   = $dbc->query($sql);
    }

    private function _linkExists($location, $pk1, $pk2)
    {
        try
        {
            $dbc    = new DatabaseConnection();
            $sql    = "SELECT * FROM `$location` WHERE pk1 = $pk1 AND pk2 = $pk2";
            $rows   = $dbc->query($sql);

            if (count($rows))
            {
                return true;
            }

            return false;
        }
        catch (Exception $e)
        {
            throw new Exception("Could not find link due to bad sql: $sql, ".$e->getMessage());
        }

    }

    private function _escapeString(&$value)
    {
        $value = mysql_escape_string($value);
    }
}
