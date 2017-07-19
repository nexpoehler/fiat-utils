<?php

namespace Nexcess;

use Symfony\Component\Yaml\Yaml;
use Tree\Builder\NodeBuilder;
use Tree\Visitor\PostOrderVisitor;
use Tree\Node\Node;

final class FiatUtils {

    /**
     * Given a fiat file, return a json link data object.
     *
     * @param string $fiat_file Fiat file name.
     * @return string
     */
    public static function fiat2LinkData($fiat_file) {
        $nodes = self::_getServerDefs($fiat_file);
        $links = self::_getLinks($fiat_file);
        $combo = array_merge($nodes, $links);
        return json_encode($combo, JSON_PRETTY_PRINT);
    }
    
    /**
     * Defines the role connection data.
     * 
     * @return Tree\Node\Node
     */
    private static function _baseConnectionTree() {
        $builder = new NodeBuilder();
        $builder
            ->value('lb-external')
            ->tree('lb-varnish')
            ->tree('varnish')
            ->tree('lb-web')
            ->tree('web')
            ->leaf('fs')
            ->tree('lb-fpm')
            ->tree('fpm')
            ->leaf('fs')
            ->leaf('db')
            ->tree('lb-redis')
            ->tree('redis')
            ->leaf('db')
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end();
        return $builder->getNode();
    }

    /**
     * Calculates and returns server role counts from fiat.
     * 
     * @param string $fiat_file Fiat file name.
     * @return array
     */
    private static function _getServerRoleCounts( array $servers ) {
        $output = [];
        foreach( $servers as $server ) {
            if( isset( $server['quantity'] ) ) {
                $quantity = $server['quantity'];
            } else {
                $quantity = 1;
            }
            if( !isset( $output[ $server['role'] ] ) ) {
                $output[ $server['role'] ] = 0;
            }
            $output[ $server['role'] ] += $quantity;
        }
        return $output;
    }

    /**
     * Convert fiat into server data with IDs.
     * 
     * @param string $fiat_file Fiat file name.
     * @return array
     */
    private static function _getServerDefs($fiat_file) {
        $server_defs = [ 'nodes' => [] ];
        $parsed_fiat = Yaml::parse(file_get_contents($fiat_file));
        $server_role_counts = self::_getServerRoleCounts($parsed_fiat['environment']['hardware']['servers']);
        foreach($server_role_counts as $role => $quantity) {
            for($i = 1; $i <= $quantity; $i++) {
                $server_defs['nodes'][] = [
                    'role' => $role,
                    'id' => "{$role}-{$i}",
                ];
            }
        }
        return $server_defs;
    }

    /**
     * Convert fiat into server link data.
     *
     * @param string $fiat_file Fiat file name.
     * @return array
     */
    private static function _getLinks($fiat_file) {
        $links = [ 'links' => [] ];
        $parsed_fiat = Yaml::parse( file_get_contents( $fiat_file ) );
        $basetree = self::_baseConnectionTree();
        $server_role_counts = self::_getServerRoleCounts($parsed_fiat['environment']['hardware']['servers']);
        $visitor = new PostOrderVisitor();
        $nodes = $basetree->accept($visitor);
        foreach( $nodes as $node ) {
            if( !$node->isRoot() ) {
                for($i = 1; $i <= $server_role_counts[ $node->getValue() ]; $i++) {
                    for($j = 1; $j <= $server_role_counts[ $node->getParent()->getValue() ]; $j++) {
                        $links[ 'links' ][] = [
                            'from' => $node->getParent()->getValue() . "-{$j}",
                            'to' => $node->getValue() . "-{$i}"
                        ];
                    }
                }
            }
        }
        $links['links'] = array_reverse($links['links']);
        return $links;
    }
    
}
