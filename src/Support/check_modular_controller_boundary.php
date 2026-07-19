<?php

declare(strict_types=1);

namespace App\Controllers {
    class BoundaryLegacyFixture {}
}

namespace App\Support\BoundaryFixtures {
    class CleanBase {}
    class CleanController extends CleanBase {}
    class DirectLegacyController extends \App\Controllers\BoundaryLegacyFixture {}
    class IndirectLegacyBase extends \App\Controllers\BoundaryLegacyFixture {}
    class IndirectLegacyController extends IndirectLegacyBase {}
}

namespace {
    require_once __DIR__ . '/ModularControllerBoundary.php';

    use App\Support\BoundaryFixtures\CleanController;
    use App\Support\BoundaryFixtures\DirectLegacyController;
    use App\Support\BoundaryFixtures\IndirectLegacyController;
    use App\Support\ModularControllerBoundary;

    $checks = [
        'clean source is accepted' => ModularControllerBoundary::sourceViolations(
            '<?php namespace App\\Modules\\Commerce\\Controllers; class Orders {}'
        ) === [],
        'legacy inheritance source is rejected' => ModularControllerBoundary::sourceViolations(
            '<?php class Orders extends \\App\\Controllers\\OrderController {}'
        ) !== [],
        'legacy import/composition source is rejected' => ModularControllerBoundary::sourceViolations(
            '<?php use App\\Controllers\\OrderController; $legacy = new OrderController();'
        ) !== [],
        'bounded page handler is accepted' => ModularControllerBoundary::httpHandlerListViolations(
            '<?php public function index() { return $repository->getPage([\'limit\' => 100]); }'
        ) === [],
        'getAll handler is rejected' => ModularControllerBoundary::httpHandlerListViolations(
            '<?php public function index() { return $repository->getAll(); }'
        ) !== [],
        'getAll text in comments and strings is ignored' => ModularControllerBoundary::httpHandlerListViolations(
            '<?php public function index() { /* $repository->getAll(); */ return "->getAll("; }'
        ) === [],
        'unbounded personal history is rejected semantically' => ModularControllerBoundary::httpHandlerListViolations(
            '<?php public function index() { return $repository -> getByUserId($id); }'
        ) !== [],
        'clean neutral base is accepted' => ModularControllerBoundary::reflectionViolations(
            new ReflectionClass(CleanController::class)
        ) === [],
        'direct legacy parent is rejected' => ModularControllerBoundary::reflectionViolations(
            new ReflectionClass(DirectLegacyController::class)
        ) !== [],
        'indirect legacy parent is rejected' => ModularControllerBoundary::reflectionViolations(
            new ReflectionClass(IndirectLegacyController::class)
        ) !== [],
    ];

    $failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
    if ($failed !== []) {
        fwrite(STDERR, "Modular controller boundary test failed:\n- " . implode("\n- ", $failed) . "\n");
        exit(1);
    }

    echo "Modular controller boundary test: OK\n";
}
