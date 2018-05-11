<?php

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
    public function testFilterDuplicates(array $input_array, array $output_array)
    {
        $command = new FindDuplicatesCommand();
        $this->assertEquals($command->filterDuplicates($input_array), $output_array);
    }

    /**
     * @param array $input_array
     * @param array $output_array
     *
     * @dataProvider provideSampleTimeEntryArray
     */
    public function testIndexEntries(array $input_array, array $output_array)
    {
        $command = new FindDuplicatesCommand();
        $command->setShortForm(true);
        $this->assertEquals($command->indexEntriesByHarvestId($input_array), $output_array);
    }

    /**
     * Provide sample time entry array and expected output.
     *
     * The output uses the short form version (Redmine entry ID).
     *
     * @return array
     */
    public function provideSampleTimeEntryArray()
    {
        return [
            [
                [
                    123 => [
                        'id' => 567,
                        'custom_fields' => [
                            [
                                'id' => 20,
                                'value' => 123565,
                            ]
                        ]
                    ],
                ],
                [
                    123565 => [
                        567
                    ]
                ]
            ],
            [
                [
                    123 => [
                        'id' => 567,
                        'custom_fields' => [
                            [
                                'id' => 20,
                                'value' => 123565,
                            ]
                        ]
                    ],
                    124 => [
                        'id' => 4,
                        'custom_fields' => [
                            [
                                'id' => 20,
                                'value' => 123565,
                            ]
                        ]
                    ]
                ],
                [
                    123565 => [
                        567,
                        4
                    ]
                ]
            ],
            [
                [
                    123 => [
                        'id' => 567,
                        'custom_fields' => [
                            [
                                'id' => 20,
                                'value' => 123565,
                            ]
                        ]
                    ],
                    124 => [
                        'id' => 4,
                        'custom_fields' => [
                            [
                                'id' => 20,
                                'value' => 123565,
                            ]
                        ]
                    ],
                    125 => [
                        'id' => 5
                    ]
                ],
                [
                    123565 => [
                        567,
                        4
                    ]
                ]
            ]
        ];
    }

    /**
     * Data provider for testFilterDuplicates().
     *
     * @return array
     */
    public function provideSampleDuplicateArrays()
    {
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
