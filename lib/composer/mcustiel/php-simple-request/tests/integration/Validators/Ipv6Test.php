<?php
/**
 * This file is part of php-simple-request.
 *
 * php-simple-request is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * php-simple-request is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with php-simple-request.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Integration\Validators;

class Ipv6Test extends AbstractValidatorTest
{
    const TEST_FIELD = 'ipv6';

    public function testBuildARequestWithInvalidValue()
    {
        $this->request[self::TEST_FIELD] = '2001:0db8:85a3:08d3:1319:8a2e:0370:733g';
        $this->buildRequestAndTestErrorFieldPresent(self::TEST_FIELD);
    }
}
