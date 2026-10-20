<?php

declare(strict_types=1);

namespace PrintPreflight\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrintPreflight\Exceptions\InvalidProfileException;
use PrintPreflight\Exceptions\InvalidRuleConfigException;
use PrintPreflight\Profile;
use PrintPreflight\Rules\BleedMarginRule;
use PrintPreflight\Rules\PageCountRule;
use PrintPreflight\Rules\Severity;
use PrintPreflight\Rules\UndeterminedPolicy;

final class ProfileTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../../examples/profiles/business-card.json';

    public function testExampleProfileLoadsAndRoundTrips(): void
    {
        $profile = Profile::fromJson((string) file_get_contents(self::EXAMPLE));
        self::assertSame('business-card', $profile->id);
        self::assertSame(2, $profile->version);
        self::assertCount(9, $profile->rules);
        self::assertSame(Severity::Warning, $profile->rules[3]->severity);
        self::assertFalse($profile->rules[8]->enabled);

        $again = Profile::fromJson($profile->toJson());
        self::assertEquals($profile, $again);
        self::assertCount(8, $profile->createRules(), 'disabled rules are not instantiated');
    }

    public function testRuleOptionsArePassedThrough(): void
    {
        $profile = Profile::fromArray([
            'id' => 'p',
            'name' => 'P',
            'rules' => [[
                'type' => 'bleed_margin',
                'id' => 'bleed-front',
                'severity' => 'warning',
                'on_undetermined' => 'ignore',
                'params' => ['min_bleed' => 2, 'pages' => 'first'],
                'messages' => ['bleed.insufficient' => 'Zu wenig Beschnitt'],
            ]],
        ]);
        $rule = $profile->createRules()[0];
        self::assertInstanceOf(BleedMarginRule::class, $rule);
        self::assertSame('bleed-front', $rule->getId());
        self::assertSame(Severity::Warning, $rule->getOptions()->severity);
        self::assertSame(UndeterminedPolicy::Ignore, $rule->getOptions()->onUndetermined);
        self::assertSame(['bleed.insufficient' => 'Zu wenig Beschnitt'], $rule->getOptions()->messages);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidProfiles(): iterable
    {
        $rule = ['type' => 'page_count', 'params' => ['min' => 1]];
        yield 'missing id' => [['name' => 'x', 'rules' => []], 'id'];
        yield 'unknown top-level key' => [['id' => 'x', 'name' => 'x', 'rules' => [], 'foo' => 1], 'foo'];
        yield 'rules not a list' => [['id' => 'x', 'name' => 'x', 'rules' => ['a' => $rule]], 'rules'];
        yield 'unknown schema' => [['schema' => 2, 'id' => 'x', 'name' => 'x', 'rules' => []], 'schema'];
        yield 'invalid severity' => [['id' => 'x', 'name' => 'x', 'rules' => [$rule + ['severity' => 'fatal']]], 'rules[0].severity'];
        yield 'invalid policy' => [['id' => 'x', 'name' => 'x', 'rules' => [$rule + ['on_undetermined' => 'maybe']]], 'rules[0].on_undetermined'];
        yield 'params as list' => [['id' => 'x', 'name' => 'x', 'rules' => [['type' => 'page_count', 'params' => [1, 2]]]], 'rules[0].params'];
        yield 'duplicate rule ids' => [['id' => 'x', 'name' => 'x', 'rules' => [$rule, $rule]], 'duplicate'];
        yield 'unknown rule key' => [['id' => 'x', 'name' => 'x', 'rules' => [$rule + ['prio' => 1]]], 'rules[0].prio'];
    }

    /**
     * @param array<mixed> $data
     */
    #[DataProvider('invalidProfiles')]
    public function testInvalidProfiles(array $data, string $messagePart): void
    {
        $this->expectException(InvalidProfileException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messagePart, '/') . '/');
        Profile::fromArray($data);
    }

    public function testInvalidJson(): void
    {
        $this->expectException(InvalidProfileException::class);
        Profile::fromJson('{');
    }

    public function testUnknownRuleTypeIsReportedWithPath(): void
    {
        $profile = Profile::fromArray(['id' => 'x', 'name' => 'x', 'rules' => [['type' => 'page_count', 'params' => ['min' => 1]], ['type' => 'does_not_exist']]]);
        try {
            $profile->createRules();
            self::fail('exception expected');
        } catch (InvalidProfileException $e) {
            self::assertSame('rules[1].type', $e->getPath());
        }
    }

    public function testInvalidRuleParameterIsReportedWithPath(): void
    {
        $profile = Profile::fromArray(['id' => 'x', 'name' => 'x', 'rules' => [['type' => 'page_size', 'params' => ['width' => 'wide']]]]);
        try {
            $profile->createRules();
            self::fail('exception expected');
        } catch (InvalidRuleConfigException $e) {
            self::assertSame('rules[0].params', $e->getPath());
            self::assertStringContainsString('width', $e->getMessage());
        }
    }

    public function testRuleConfigReachesRule(): void
    {
        $rules = Profile::fromArray(['id' => 'x', 'name' => 'x', 'rules' => [['type' => 'page_count', 'params' => ['exact' => 2]]]])->createRules();
        self::assertInstanceOf(PageCountRule::class, $rules[0]);
    }
}
