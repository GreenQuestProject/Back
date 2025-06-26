<?php

namespace App\Controller;

use App\Entity\Challenge;
use App\Entity\Progression;
use App\Enum\ChallengeCategory;
use App\Repository\ChallengeRepository;
use App\Repository\ProgressionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use Psr\Cache\InvalidArgumentException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use OpenApi\Attributes as OA;

final class ChallengeController extends AbstractController
{

    /**
     * Create new challenge entry
     *
     * @param Request $request
     * @param SerializerInterface $serializer
     * @param EntityManagerInterface $entityManager
     * @param UrlGeneratorInterface $urlgenerator
     * @param ValidatorInterface $validator
     * @param TagAwareCacheInterface $cache
     * @return JsonResponse
     * @throws InvalidArgumentException
     */
    #[Route('/api/challenge', name: 'challenge_create', methods: ['POST'])]
    #[IsGranted("ROLE_ADMIN")]
    public function create(Request                $request, SerializerInterface $serializer,
                           EntityManagerInterface $entityManager, UrlGeneratorInterface $urlgenerator,
                           ValidatorInterface     $validator, TagAwareCacheInterface $cache): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Missing required field. Name is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $challenge = $serializer->deserialize($request->getContent(), Challenge::class, "json");

        $data = $request->toArray();

        $errors = $validator->validate($challenge);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), Response::HTTP_BAD_REQUEST, [], true);
        }

        $challenge->setName($data["name"])
            ->setDescription($data["description"])
            ->setCategory(ChallengeCategory::from($data["category"]));

        $entityManager->persist($challenge);

        $entityManager->flush();

        $cache->invalidateTags(["challengeCache"]);

        $jsonChallenge = $serializer->serialize($challenge, 'json', ['groups' => "getAll"]);
        $location = $urlgenerator->generate("challenge_get", ["idChallenge" => $challenge->getId()], UrlGeneratorInterface::ABSOLUTE_PATH);
        return new JsonResponse($jsonChallenge, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    /**
     * Update challenge entry
     *
     * @param Challenge $challenge
     * @param Request $request
     * @param SerializerInterface $serializer
     * @param EntityManagerInterface $entityManager
     * @param TagAwareCacheInterface $cache
     * @return JsonResponse
     * @throws InvalidArgumentException
     */
    #[Route('/api/challenge/{id}', name: 'challenge_update', methods: ['PUT'])]
    #[IsGranted("ROLE_ADMIN")]
    public function update(Challenge              $challenge, Request $request, SerializerInterface $serializer,
                           EntityManagerInterface $entityManager, TagAwareCacheInterface $cache): JsonResponse
    {
        $updatedChallenge = $serializer->deserialize($request->getContent(), Challenge::class, "json", [AbstractNormalizer::OBJECT_TO_POPULATE => $challenge]);
        $entityManager->persist($updatedChallenge);
        $entityManager->flush();

        $cache->invalidateTags(["challengeCache"]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Delete challenge entry
     *
     * @param Challenge $challenge
     * @param EntityManagerInterface $entityManager
     * @param TagAwareCacheInterface $cache
     * @return JsonResponse
     * @throws InvalidArgumentException
     */
    #[Route('/api/challenge/{id}', name: 'challenge_delete', methods: ['DELETE'])]
    #[IsGranted("ROLE_ADMIN")]
    public function delete(Challenge $challenge, EntityManagerInterface $entityManager, TagAwareCacheInterface $cache): JsonResponse
    {
        $entityManager->remove($challenge);
        $entityManager->flush();

        $cache->invalidateTags(["challengeCache"]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Return challenge entry
     *
     * @param Challenge|null $challenge
     * @param SerializerInterface $serializer
     * @return JsonResponse
     */
    #[OA\Response(
        response: 200,
        description: "Return one challenge",
        content: new OA\JsonContent(
            type: "array",
            items: new OA\Items(ref: new Model(type: Challenge::class))
        )
    )]
    #[Route('/api/challenge/{idChallenge}', name: 'challenge_get', methods: ['GET'])]
    public function get(#[MapEntity(mapping: ['idChallenge' => 'id'])] ?Challenge $challenge, SerializerInterface $serializer): JsonResponse
    {
        if (!$challenge) {
            return new JsonResponse(['message' => 'Challenge not found'], Response::HTTP_NOT_FOUND);
        }

        $jsonChallenge = $serializer->serialize($challenge, 'json', ['groups' => "getAll"]);

        return new JsonResponse($jsonChallenge, 200, [], true);
    }

    /**
     * Returns all challenges
     *
     * @param Request $request
     * @param ChallengeRepository $challengeRepo
     * @param ProgressionRepository $progressionRepo
     * @param TagAwareCacheInterface $cache
     * @return JsonResponse
     * @throws InvalidArgumentException
     */
    #[OA\Response(
        response: 200,
        description: "Return all challenges",
        content: new OA\JsonContent(
            type: "array",
            items: new OA\Items(ref: new Model(type: Challenge::class))
        )
    )]
    #[Route('/api/challenge', name: 'challenge_getAll', methods: ['GET'])]
    public function getAll(
        Request                $request,
        ChallengeRepository    $challengeRepo,
        ProgressionRepository  $progressionRepo,
        TagAwareCacheInterface $cache
    ): JsonResponse
    {
        $user = $this->getUser();
        $category = $request->query->get('category');
        $cacheId = 'getAllChallenge-' . ($category ?? 'all');

        $cache->delete($cacheId);

        $challenges = $cache->get($cacheId, function (ItemInterface $item) use ($challengeRepo, $category) {
            $item->tag('challengeCache');
            return $challengeRepo->findWithFilters($category);
        });

        if (!is_iterable($challenges)) {
            $challenges = [];
        }

        $userProgressions = $progressionRepo->findBy(['user' => $user]);

        $challengeIdsWithProgression = array_unique(array_filter(array_map(
            fn(Progression $progression) => $progression->getChallenge()?->getId(),
            $userProgressions
        )));

        $data = array_map(function (Challenge $challenge) use ($challengeIdsWithProgression) {
            return [
                'id' => $challenge->getId(),
                'name' => $challenge->getName(),
                'description' => $challenge->getDescription(),
                'category' => $challenge->getCategory(),
                'isInUserProgression' => in_array($challenge->getId(), $challengeIdsWithProgression),
            ];
        }, is_array($challenges) ? $challenges : iterator_to_array($challenges));

        return new JsonResponse($data, 200);
    }
}
