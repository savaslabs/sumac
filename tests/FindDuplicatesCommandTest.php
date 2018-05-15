<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Sumac\Console\Command\Sync\FindDuplicatesCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

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
        $this->assertEquals($command->getDuplicates($input_array), $output_array);
    }

    public function testFindDuplicatesCommandNonExistentConfig()
    {
        $application = new Application();
        $application->add(new FindDuplicatesCommand());
        $command = $application->find('sync:find-duplicates');
        $command_tester = new CommandTester($command);
        try {
            $command_tester->execute(
                [
                'command' => $command->getName(),
                '--config' => 'doesntexist.yml',
                ]
            );
        } catch (\Exception $exception) {
            $this->assertContains('Could not find the config.yml file at doesntexist.yml', $exception->getMessage());
        }
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
                        456,
                        789
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
                        456,
                        789
                    ]
                ]
            ]
        ];
    }
}
