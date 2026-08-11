<?php

namespace Tests\Unit\Services;

use App\Services\TestExecution\JunitXmlParser;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class JunitXmlParserTest extends TestCase
{
    private string $xmlPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->xmlPath = sys_get_temp_dir().'/boschifai-junit-'.uniqid().'.xml';
    }

    protected function tearDown(): void
    {
        File::delete($this->xmlPath);
        parent::tearDown();
    }

    public function test_parse_reports_every_test_with_its_own_status_not_just_failures(): void
    {
        File::put($this->xmlPath, <<<XML
        <?xml version="1.0"?>
        <testsuites>
            <testsuite name="CustomFieldControllerTest" tests="3" failures="1" skipped="1" time="0.45">
                <testcase name="test_index_returns_200" class="Tests\Feature\CustomFieldControllerTest" time="0.20"/>
                <testcase name="test_store_validates_input" class="Tests\Feature\CustomFieldControllerTest" time="0.15">
                    <failure message="Failed asserting that 422 matches expected 200.">Stack trace here</failure>
                </testcase>
                <testcase name="test_destroy_requires_permission" class="Tests\Feature\CustomFieldControllerTest" time="0.10">
                    <skipped/>
                </testcase>
            </testsuite>
        </testsuites>
        XML);

        $result = (new JunitXmlParser())->parse($this->xmlPath);

        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['passed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(450, $result['duration_ms']);

        $this->assertSame([
            [
                'name' => 'Tests\Feature\CustomFieldControllerTest::test_index_returns_200',
                'status' => 'passed',
                'message' => null,
            ],
            [
                'name' => 'Tests\Feature\CustomFieldControllerTest::test_store_validates_input',
                'status' => 'failed',
                'message' => 'Failed asserting that 422 matches expected 200.',
            ],
            [
                'name' => 'Tests\Feature\CustomFieldControllerTest::test_destroy_requires_permission',
                'status' => 'skipped',
                'message' => null,
            ],
        ], $result['tests']);
    }
}
