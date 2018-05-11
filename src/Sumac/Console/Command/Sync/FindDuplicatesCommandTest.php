<?php
/**
 * Created by PhpStorm.
 * User: kostajh
 * Date: 5/11/18
 * Time: 09:20
 */

namespace Sumac\Console\Command\Sync;


use PHPUnit\Framework\TestCase;

class FindDuplicatesCommandTest extends TestCase
{

    /**
     * @param $input_array
     * @param $output_array
     *
     * @dataProvider provideSampleDuplicateArrays
     */
    public function testFilterDuplicates($input_array, $output_array)
    {
        $command = new FindDuplicatesCommand();
        $this->assertEquals($command->filterDuplicates($input_array), $output_array);

    }

    public function provideSampleDuplicateArrays() {
        return [
            [
                [
                    123 => [
                        456,
                        789
                    ]
                ],
                [
                    123 => [
                        456
                    ]
                ]
            ],
            [
                [
                    123 => [
                        123,
                        456,
                        789,
                        111
                    ],
                    456 => [
                        123
                    ]
                ],
                [
                    123 => [
                        111,
                        123,
                        456
                    ]
                ]
            ]
        ];
    }
}
