<?php
/**
 * Copyright (c) Enalean, 2017. All Rights Reserved.
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
 * along with Tuleap. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tuleap\Captcha;

class DataAccessObject extends \DataAccessObject
{
    /**
     * @return array|false
     */
    public function getConfiguration()
    {
        $sql = 'SELECT * FROM plugin_captcha_configuration';

        return $this->retrieveFirstRow($sql);
    }

    /**
     * @return bool
     */
    public function save($site_key, $secret_key)
    {
        $site_key   = $this->da->quoteSmart($site_key);
        $secret_key = $this->da->quoteSmart($secret_key);

        $this->startTransaction();

        $sql_delete = "DELETE FROM plugin_captcha_configuration";

        if (! $this->update($sql_delete)) {
            $this->rollBack();
            return false;
        }

        $sql_save = "INSERT INTO plugin_captcha_configuration(site_key, secret_key) VALUES ($site_key, $secret_key)";

        if (! $this->update($sql_save)) {
            $this->rollBack();
            return false;
        }

        $this->commit();

        return true;
    }
}
