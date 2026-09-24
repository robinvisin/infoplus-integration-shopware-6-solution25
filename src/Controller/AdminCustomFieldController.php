<?php

namespace InfoPlusCommerce\Controller;

use InfoPlusCommerce\Service\AdminCustomFieldService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[\Symfony\Component\Routing\Attribute\Route(defaults: ['_routeScope' => ['api']])]
class AdminCustomFieldController extends AbstractController
{
    private AdminCustomFieldService $customFieldService;

    public function __construct(AdminCustomFieldService $customFieldService)
    {
        $this->customFieldService = $customFieldService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/api/_action/infoplus/customfields', name: 'api.infoplus.customfields.list', methods: ['GET'])]
    public function list(Request $request, Context $context): JsonResponse
    {
        $data = $this->customFieldService->list(false, $context);
        return new JsonResponse($data);
    }
    #[\Symfony\Component\Routing\Attribute\Route(path: '/api/_action/infoplus/customfields/all', name: 'api.infoplus.customfields.list.all', methods: ['GET'])]
    public function listAll(Request $request, Context $context): JsonResponse
    {
        $data = $this->customFieldService->list(true, $context);
        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/api/_action/infoplus/customfields/{id}', name: 'api.infoplus.customfields.get', methods: ['GET'])]
    public function get(string $id, Context $context): JsonResponse
    {
        $data = $this->customFieldService->get($id, $context);
        if (!$data) {
            return new JsonResponse([], Response::HTTP_NOT_FOUND);
        }
        return new JsonResponse($data);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/api/_action/infoplus/customfields', name: 'api.infoplus.customfields.create', methods: ['POST'])]
    public function create(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        try {
            $this->customFieldService->create($data, $context);
            return new JsonResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/api/_action/infoplus/customfields/{id}', name: 'api.infoplus.customfields.update', methods: ['PUT'])]
    public function update(string $id, Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        try {
            $this->customFieldService->update($id, $data, $context);
            return new JsonResponse(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/api/_action/infoplus/customfields/{id}', name: 'api.infoplus.customfields.delete', methods: ['DELETE'])]
    public function delete(string $id, Context $context): JsonResponse
    {
        $this->customFieldService->delete($id, $context);
        return new JsonResponse(['success' => true]);
    }
}
