<?php

namespace App\PublicApi\Controller;

use App\Service\FormFieldService;
use App\Service\FormService;
use App\Service\FormSubmissionService;
use App\Service\ReCaptchaService;
use App\Service\TokenProtectionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class FormController extends AbstractController
{
    public function __construct(
        private readonly FormService $formService,
        private readonly FormFieldService $formFieldService,
        private readonly FormSubmissionService $formSubmissionService,
        private readonly ReCaptchaService $reCaptchaService,
    )
    {
    }

    #[Route('/api/forms/{hash}', name: 'public_api_form', methods: ['GET', 'POST', 'OPTIONS'], format: 'json')]
    public function form(Request $request, string $hash): JsonResponse
    {
        $form = $this->formService->getByHash($hash);
        if (!$form) {
            return $this->json(['error' => 'Form not found'], 404);
        }

        if ($form->isTokenEnabled()) {
            $tokenProtectionService = new TokenProtectionService($form);
            $token = $request->headers->get('Authorization');
            if (!$token || !$tokenProtectionService->isTokenValid($token)) {
                return $this->json(['error' => 'Invalid token'], 401);
            }
        }

        if ($request->getMethod() === 'POST') {
            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['error' => 'Invalid request body'], 400);
            }

            // Recaptcha validation
            if($this->getParameter('env') === 'prod' && $form->isRecaptchaEnabled()) {
                $recaptchaResponse = $data['recaptchaResponse'] ?? null;
                if (!$recaptchaResponse) {
                    return $this->json(['error' => 'Missing recaptcha response'], 400);
                }
                $recaptchaValidationResult = $this->reCaptchaService->validate($recaptchaResponse, $form->getRecaptchaToken(), $request->getClientIp());
                if (!$recaptchaValidationResult) {
                    return $this->json(['error' => 'Invalid recaptcha'], 400);
                }
            }

            $submitted = $this->formSubmissionService->submit($form->getId(), $data);
            $response = $this->json($submitted);
        } else {
            $formFields = $this->formFieldService->getAllByFormId($form->getId());

            $response = $this->json([
                'fields' => array_map(fn($formField) => [
                    'type' => $formField->getType(),
                    'name' => $formField->getName(),
                    'label' => $formField->getLabel(),
                    'hint' => $formField->getHint(),
                    'isRequired' => $formField->getIsRequired(),
                    'validations' => $formField->getValidations(),
                ], $formFields),
            ]);
        }

        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

        return $response;
    }
}