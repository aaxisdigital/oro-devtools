<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Controller;

use Aaxis\Bundle\DevToolsBundle\Controller\DatabaseViewerController;
use Aaxis\Bundle\DevToolsBundle\Controller\ElasticViewerController;
use Aaxis\Bundle\DevToolsBundle\Entity\Repository\SavedQueryRepository;
use Aaxis\Bundle\DevToolsBundle\Entity\SavedQuery;
use Aaxis\Bundle\DevToolsBundle\Exception\DuplicateQueryNameException;
use Aaxis\Bundle\DevToolsBundle\Manager\SavedQueryManager;
use Aaxis\Bundle\DevToolsBundle\Tests\Unit\Support\ControllerTestCase;
use Oro\Bundle\UserBundle\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * The favourites endpoints live in SavedQueryEndpointsTrait and are shared by the Database and the
 * Elastic viewers; each only supplies its tool. Exercised through both controllers.
 */
class SavedQueryEndpointsTest extends ControllerTestCase
{
    private SavedQueryManager&MockObject $manager;
    private SavedQueryRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(SavedQueryManager::class);
        $this->repository = $this->createMock(SavedQueryRepository::class);
        $this->manager->method('getRepository')->willReturn($this->repository);
    }

    /**
     * @return iterable<string, array{class-string<AbstractController>, string}>
     */
    public static function controllerProvider(): iterable
    {
        yield 'database viewer' => [DatabaseViewerController::class, SavedQuery::TOOL_DATABASE];
        yield 'elastic viewer' => [ElasticViewerController::class, SavedQuery::TOOL_ELASTIC];
    }

    /**
     * @param class-string<AbstractController> $class
     */
    private function controller(string $class, ?int $userId = 7): DatabaseViewerController|ElasticViewerController
    {
        $this->manager->method('getCurrentUserId')->willReturn($userId);
        $controller = new $class();
        $this->wire($controller, [SavedQueryManager::class => $this->manager]);

        return $controller;
    }

    private function user(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function query(?int $ownerId, string $name = 'Q', string $tool = SavedQuery::TOOL_DATABASE, bool $public = false, int $id = 1): SavedQuery
    {
        $query = (new SavedQuery())
            ->setUser($ownerId === null ? null : $this->user($ownerId))
            ->setQueryName($name)
            ->setQueryText('text of ' . $name)
            ->setPublic($public)
            ->setTool($tool);
        (new \ReflectionProperty(SavedQuery::class, 'id'))->setValue($query, $id);

        return $query;
    }

    /**
     * @dataProvider controllerProvider
     */
    public function testTheMenuListsPublicAndPrivateQueriesAndMarksTheOwnOnes(string $class, string $tool): void
    {
        $controller = $this->controller($class);
        $this->manager->expects(self::once())->method('getMenuData')->with(7, $tool)->willReturn([
            'public' => [$this->query(7, 'mine', $tool, true, 1), $this->query(8, 'theirs', $tool, true, 2)],
            'private' => [$this->query(7, 'secret', $tool, false, 3)],
            'hasMorePublic' => true,
            'hasMorePrivate' => false,
        ]);

        $data = $this->decode($controller->savedQueriesAction());

        self::assertSame([
            ['id' => 1, 'name' => 'mine', 'public' => true, 'text' => 'text of mine', 'owned' => true],
            ['id' => 2, 'name' => 'theirs', 'public' => true, 'text' => 'text of theirs', 'owned' => false],
        ], $data['public']);
        self::assertSame([['id' => 3, 'name' => 'secret', 'public' => false, 'text' => 'text of secret', 'owned' => true]], $data['private']);
        self::assertTrue($data['hasMorePublic']);
        self::assertFalse($data['hasMorePrivate']);
    }

    /**
     * @dataProvider controllerProvider
     */
    public function testAnAnonymousMenuUsesUserIdZero(string $class, string $tool): void
    {
        $controller = $this->controller($class, null);
        $this->manager->expects(self::once())->method('getMenuData')->with(0, $tool)->willReturn([
            'public' => [], 'private' => [], 'hasMorePublic' => false, 'hasMorePrivate' => false,
        ]);

        self::assertSame([], $this->decode($controller->savedQueriesAction())['public']);
    }

    /**
     * @dataProvider controllerProvider
     */
    public function testTheFullListIsScopedByToolAndUser(string $class, string $tool): void
    {
        $controller = $this->controller($class);
        $orphan = $this->query(null, 'orphan', $tool, true, 5);
        $this->repository->expects(self::once())->method('findAllVisibleForUser')->with($tool, 7)
            ->willReturn([$this->query(7, 'mine', $tool, false, 4), $orphan]);

        $data = $this->decode($controller->savedQueriesAllAction());

        self::assertSame([
            ['id' => 4, 'name' => 'mine', 'public' => false, 'text' => 'text of mine', 'owned' => true],
            ['id' => 5, 'name' => 'orphan', 'public' => true, 'text' => 'text of orphan', 'owned' => false],
        ], $data['items']);
    }

    /**
     * @dataProvider controllerProvider
     */
    public function testCreateStoresTheQueryUnderTheViewersTool(string $class, string $tool): void
    {
        $controller = $this->controller($class);
        $this->manager->expects(self::once())->method('create')->with('Top', 'SELECT 1', true, $tool)
            ->willReturn($this->query(7, 'Top', $tool, true, 9));

        $response = $controller->createSavedQueryAction($this->post(['name' => '  Top  ', 'text' => 'SELECT 1', 'public' => 1]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['success' => true, 'query' => ['id' => 9, 'name' => 'Top', 'public' => true, 'text' => 'text of Top', 'owned' => true]],
            $this->decode($response)
        );
    }

    public function testCreateDefaultsToAPrivateQuery(): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $this->manager->expects(self::once())->method('create')->with('N', 'T', false, 'database')
            ->willReturn($this->query(7));

        $controller->createSavedQueryAction($this->post(['name' => 'N', 'text' => 'T']));
    }

    public function testCreateRejectsAnInvalidPayload(): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $this->manager->expects(self::never())->method('create');

        $response = $controller->createSavedQueryAction($this->post(null, '[[['));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Invalid payload.'], $this->decode($response));
    }

    /**
     * @dataProvider incompleteCreatePayloadProvider
     *
     * @param array<string, mixed> $payload
     */
    public function testCreateRequiresANameAndQueryText(array $payload): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $this->manager->expects(self::never())->method('create');

        $response = $controller->createSavedQueryAction($this->post($payload));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Name and query text are required.', $this->decode($response)['message']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incompleteCreatePayloadProvider(): iterable
    {
        yield 'no name' => [['text' => 'SELECT 1']];
        yield 'blank name' => [['name' => '   ', 'text' => 'SELECT 1']];
        yield 'no text' => [['name' => 'N']];
        yield 'blank text' => [['name' => 'N', 'text' => " \n"]];
    }

    public function testCreateReportsADuplicateNameAsAConflict(): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $this->manager->method('create')->willThrowException(new DuplicateQueryNameException('A public query named "N" already exists.'));

        $response = $controller->createSavedQueryAction($this->post(['name' => 'N', 'text' => 'T', 'public' => true]));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'A public query named "N" already exists.'], $this->decode($response));
    }

    /**
     * @dataProvider controllerProvider
     */
    public function testUpdateChangesTheProvidedFields(string $class, string $tool): void
    {
        $controller = $this->controller($class);
        $entity = $this->query(7, 'Old', $tool, false, 4);
        $this->repository->method('find')->with(4)->willReturn($entity);
        $this->manager->expects(self::once())->method('update')->with($entity, 'New', true, 'SELECT 2');

        $response = $controller->updateSavedQueryAction($this->post(['name' => ' New ', 'public' => true, 'text' => 'SELECT 2']), 4);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->decode($response)['success']);
        self::assertSame(4, $this->decode($response)['query']['id']);
    }

    public function testUpdateLeavesOmittedFieldsAsNull(): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $entity = $this->query(7);
        $this->repository->method('find')->willReturn($entity);
        $this->manager->expects(self::once())->method('update')->with($entity, null, null, null);

        $controller->updateSavedQueryAction($this->post([]), 1);
    }

    public function testUpdateOfAMissingQueryIsNotFound(): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $this->repository->method('find')->willReturn(null);
        $this->manager->expects(self::never())->method('update');

        $response = $controller->updateSavedQueryAction($this->post(['name' => 'x']), 99);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['success' => false, 'message' => 'Saved query not found.'], $this->decode($response));
    }

    public function testAViewerCannotUpdateTheOtherViewersQueries(): void
    {
        $controller = $this->controller(ElasticViewerController::class);
        $this->repository->method('find')->willReturn($this->query(7, 'Q', SavedQuery::TOOL_DATABASE));
        $this->manager->expects(self::never())->method('update');

        self::assertSame(404, $controller->updateSavedQueryAction($this->post(['name' => 'x']), 1)->getStatusCode());
    }

    /**
     * @dataProvider notOwnedProvider
     */
    public function testOnlyTheOwnerMayUpdate(?int $ownerId): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $this->repository->method('find')->willReturn($this->query($ownerId));
        $this->manager->expects(self::never())->method('update');

        $response = $controller->updateSavedQueryAction($this->post(['name' => 'x']), 1);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('You can only update your own queries.', $this->decode($response)['message']);
    }

    /**
     * @return iterable<string, array{int|null}>
     */
    public static function notOwnedProvider(): iterable
    {
        yield 'someone else' => [8];
        yield 'orphaned' => [null];
    }

    public function testUpdateRejectsAnInvalidPayload(): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $this->repository->method('find')->willReturn($this->query(7));

        $response = $controller->updateSavedQueryAction($this->post(null, 'nope'), 1);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Invalid payload.', $this->decode($response)['message']);
    }

    public function testUpdateRejectsABlankName(): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $this->repository->method('find')->willReturn($this->query(7));
        $this->manager->expects(self::never())->method('update');

        $response = $controller->updateSavedQueryAction($this->post(['name' => '  ']), 1);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Query name is required.', $this->decode($response)['message']);
    }

    public function testUpdateRejectsBlankText(): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $this->repository->method('find')->willReturn($this->query(7));
        $this->manager->expects(self::never())->method('update');

        $response = $controller->updateSavedQueryAction($this->post(['text' => ' ']), 1);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('Query text is required.', $this->decode($response)['message']);
    }

    public function testUpdateReportsADuplicateNameAsAConflict(): void
    {
        $controller = $this->controller(DatabaseViewerController::class);
        $this->repository->method('find')->willReturn($this->query(7));
        $this->manager->method('update')->willThrowException(new DuplicateQueryNameException('taken'));

        $response = $controller->updateSavedQueryAction($this->post(['name' => 'taken']), 1);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('taken', $this->decode($response)['message']);
    }
}
