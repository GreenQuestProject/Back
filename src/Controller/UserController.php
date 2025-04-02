<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Nelmio\ApiDocBundle\Attribute\Model;
use Psr\Cache\InvalidArgumentException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use OpenApi\Attributes as OA;

final class UserController extends AbstractController{

    /**
     * Create new user entry
     *
     * @param Request $request
     * @param SerializerInterface $serializer
     * @param EntityManagerInterface $entityManager
     * @param UrlGeneratorInterface $urlgenerator
     * @param ValidatorInterface $validator
     * @param TagAwareCacheInterface $cache
     * @param UserPasswordHasherInterface $passwordHasher
     * @return JsonResponse
     * @throws InvalidArgumentException
     */
    #[Route('/api/register', name: 'user_create', methods: ['POST'])]
    public function create(Request $request, SerializerInterface $serializer,
                           EntityManagerInterface $entityManager, UrlGeneratorInterface $urlgenerator,
                           ValidatorInterface $validator, TagAwareCacheInterface $cache,
                           UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Missing required fields. Username, email, and password are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $user = $serializer->deserialize($request->getContent(), User::class, "json");

        $data = $request->toArray();

        $errors = $validator->validate($user);
        if($errors->count() > 0){
            return new JsonResponse($serializer->serialize($errors, 'json'), Response::HTTP_BAD_REQUEST, [], true);
        }
        $plaintextPassword = $data["password"];

        $hashedPassword = $passwordHasher->hashPassword(
            $user,
            $plaintextPassword
        );

        $user->setUsername($data["username"])
            ->setRoles(["USER"])
            ->setEmail($data["email"])
            ->setPassword($hashedPassword);

        $entityManager->persist($user);

        $entityManager->flush();

        $cache->invalidateTags(["userCache"]);

        $jsonUser = $serializer->serialize($user, 'json',  ['groups' => "getAll"]);
        $location = $urlgenerator->generate("user_get",  ["idUser" => $user->getId()], UrlGeneratorInterface::ABSOLUTE_PATH);
        return new JsonResponse($jsonUser, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    /**
     * Update user entry
     *
     * @param User $user
     * @param Request $request
     * @param SerializerInterface $serializer
     * @param EntityManagerInterface $entityManager
     * @param TagAwareCacheInterface $cache
     * @return JsonResponse
     * @throws InvalidArgumentException
     */
    #[Route('/api/user/{id}', name: 'user_update', methods: ['PUT'])]
    public function update(User $user, Request $request, SerializerInterface $serializer,
                           EntityManagerInterface $entityManager, TagAwareCacheInterface $cache): JsonResponse
    {
        $currentUser = $this->getUser();
        if (!$currentUser || ($currentUser->getId() !== $user->getId() && !in_array('ROLE_ADMIN', $currentUser->getRoles()))) {
            return new JsonResponse(['message' => 'Access Denied'], Response::HTTP_FORBIDDEN);
        }

        $updatedUser = $serializer->deserialize($request->getContent(), User::class, "json", [AbstractNormalizer::OBJECT_TO_POPULATE => $user]);
        $entityManager->persist($updatedUser);
        $entityManager->flush();

        $cache->invalidateTags(["userCache"]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Delete user entry
     *
     * @param User $user
     * @param EntityManagerInterface $entityManager
     * @param TagAwareCacheInterface $cache
     * @return JsonResponse
     * @throws InvalidArgumentException
     */
    #[Route('/api/user/{id}', name: 'user_delete', methods: ['DELETE'])]
    #[IsGranted("ROLE_ADMIN")]
    public function delete(User $user, EntityManagerInterface $entityManager, TagAwareCacheInterface $cache): JsonResponse
    {
        $entityManager->remove($user);
        $entityManager->flush();

        $cache->invalidateTags(["userCache"]);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Return user entry
     *
     * @param User $user
     * @param SerializerInterface $serializer
     * @param Security $security
     * @return JsonResponse
     */
    #[OA\Response(
        response:200,
        description: "Return one user",
        content: new OA\JsonContent(
            type: "array",
            items: new OA\Items(ref: new Model(type:User::class))
        )
    )]
    #[Route('/api/user/{idUser}', name: 'user_get', methods: ['GET'])]
    public function get(#[MapEntity(mapping: ['idUser' => 'id'])] ?User $user, SerializerInterface $serializer, Security $security): JsonResponse
    {
        if (!$user) {
            return new JsonResponse(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $currentUser = $security->getUser();

        if (!$currentUser) {
            return new JsonResponse(['message' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        if ($currentUser->getId() !== $user->getId() && !in_array('ROLE_ADMIN', $currentUser->getRoles())) {
            return new JsonResponse(['message' => 'Access Denied'], Response::HTTP_FORBIDDEN);
        }

        $jsonUser = $serializer->serialize($user, 'json', ['groups' => "getAll"]);

        return new JsonResponse($jsonUser, 200, [], true);
    }

    /**
     * Returns all users
     *
     * @param UserRepository $repository
     * @param SerializerInterface $serializer
     * @param TagAwareCacheInterface $cache
     * @return JsonResponse
     * @throws InvalidArgumentException
     */
    #[OA\Response(
        response:200,
        description: "Return all users",
        content: new OA\JsonContent(
            type: "array",
            items: new OA\Items(ref: new Model(type:User::class))
        )
    )]
    #[Route('/api/user', name: 'user_getAll', methods: ['GET'])]
    #[IsGranted("ROLE_ADMIN")]
    public function getAll(UserRepository $repository, SerializerInterface $serializer, TagAwareCacheInterface $cache): JsonResponse
    {
        $idCache = "getAllUser";

        $jsonUsers = $cache->get($idCache, function (ItemInterface $item) use ($repository, $serializer) {
            $item->tag("userCache");
            $users = $repository->findAll();
            return $serializer->serialize($users, 'json',  ['groups' => "getAll"]);
        });

        return new JsonResponse($jsonUsers, 200, [], true);
    }

}
