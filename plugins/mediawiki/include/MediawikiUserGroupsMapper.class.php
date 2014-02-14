<?php
/**
 * Copyright (c) Enalean, 2014. All Rights Reserved.
 *
 * This file is a part of Tuleap.
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

/**
 * This class do the mapping between Tuleap And Mediawiki groups
 */

class MediawikiUserGroupsMapper {

    const MEDIAWIKI_GROUPS_ANONYMOUS  = 'anonymous';
    const MEDIAWIKI_GROUPS_USER       = 'user';
    const MEDIAWIKI_GROUPS_BOT        = 'bot';
    const MEDIAWIKI_GROUPS_SYSOP      = 'sysop';
    const MEDIAWIKI_GROUPS_BUREAUCRAT = 'bureaucrat';

    public static $MEDIAWIKI_GROUPS_NAME = array (
        self::MEDIAWIKI_GROUPS_ANONYMOUS,
        self::MEDIAWIKI_GROUPS_USER,
        self::MEDIAWIKI_GROUPS_BOT,
        self::MEDIAWIKI_GROUPS_SYSOP,
        self::MEDIAWIKI_GROUPS_BUREAUCRAT
    );

    /** @var MediawikiDao */
    private $dao;

    public function __construct(MediawikiDao $dao) {
        $this->dao = $dao;
    }

    /**
     *
     * @param array $new_mapping_list
     * @param Project $project
     */
    public function saveMapping(array $new_mapping_list, Project $project) {
        $current_mapping_list = $this->getCurrentUserGroupMapping($project);
        $mappings_to_remove   = $this->getUserGroupMappingsDiff($current_mapping_list, $new_mapping_list);
        $mappings_to_add      = $this->getUserGroupMappingsDiff($new_mapping_list, $current_mapping_list);

        foreach (self::$MEDIAWIKI_GROUPS_NAME as $mw_group_name) {
            $this->removeMediawikiUserGroupMapping($project, $mappings_to_remove, $mw_group_name);
            $this->addMediawikiUserGroupMapping($project, $mappings_to_add, $mw_group_name);
        }
    }

    private function getUserGroupMappingsDiff($group_mapping1, $group_mapping2) {
        $list = array();

        foreach (self::$MEDIAWIKI_GROUPS_NAME as $mw_group_name) {
            if (!array_key_exists($mw_group_name, $group_mapping1)) {
                $group_mapping1[$mw_group_name] = array();
            }

            if (!array_key_exists($mw_group_name, $group_mapping2)) {
                $group_mapping2[$mw_group_name] = array();
            }

            $list[$mw_group_name] = array_diff($group_mapping1[$mw_group_name], $group_mapping2[$mw_group_name]);
        }
        return $list;
    }

    private function removeMediawikiUserGroupMapping(Project $project, array $mappings_to_remove, $mw_group_name) {
        foreach($mappings_to_remove[$mw_group_name] as $ugroup_id) {
            $this->dao->removeMediawikiUserGroupMapping($project, $mw_group_name, $ugroup_id);
        }
    }

    private function addMediawikiUserGroupMapping(Project $project, array $mappings_to_add, $mw_group_name) {
        foreach($mappings_to_add[$mw_group_name] as $ugroup_id) {
            $this->dao->addMediawikiUserGroupMapping($project, $mw_group_name, $ugroup_id);
        }
    }

    public function getCurrentUserGroupMapping($project) {
        $list = array();
        $data_result = $this->dao->getMediawikiUserGroupMapping($project);

        foreach (self::$MEDIAWIKI_GROUPS_NAME as $mw_group_name) {
            $list[$mw_group_name] = array();
            foreach ($data_result as $mapping) {
                if ($mapping['mw_group_name'] == $mw_group_name) {
                    $list[$mw_group_name][] = $mapping['ugroup_id'];
                }
            }
        }

        return $list;
    }
}