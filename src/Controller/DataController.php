<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mailer\MailerInterface;
use App\Repository\UserRepository;
use Symfony\Component\Mime\Email;
use League\Csv\Reader;
use App\Enum\UserRole;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
class DataController extends AbstractController

{
    // Upload Data 
    #[Route('/api/upload', name: 'api_upload', methods: ['POST'])]
    public function upload(Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }
        $csvContent = file_get_contents($file->getPathname());
        $csv = Reader::createFromString($csvContent);
        $csv->setHeaderOffset(0);
        $users = [];
        foreach ($csv as $row) {
            
            $user = new User();
            $user->setName($row['name']);
            $user->setEmail($row['email']);
            $user->setUsername($row['username']);
            $user->setAddress($row['address']);
            if($row['role']=='USER'){
                $user->setRole(UserRole::USER);
            }else if($row['role']=='ADMIN'){
                $user->setRole(UserRole::ADMIN);
            }
            

            $entityManager->persist($user);
            $users[] = $user;
        }
        $entityManager->flush();

        // Send emails asynchronously
        foreach ($users as $user) {
            $this->sendEmailAsync($mailer , $user);
        }

        return new JsonResponse(['message' => 'Data uploaded successfully'], Response::HTTP_OK);
    }

    private function sendEmailAsync(MailerInterface $mailer , User $user): JsonResponse
    {
         try{
            $email = (new Email())
            ->from('admin@persist.com')
            ->to($user->getEmail())
            ->subject('Welcome to User Data Management')
            ->text('Your data has been successfully stored in our database.');

         $mailer->send($email);
        return new JsonResponse(['message' => 'Email Send Success Full'], Response::HTTP_OK);
         }catch (TransportExceptionInterface $e) {
            // Log the detailed error
            error_log('Mailer Error: ' . $e->getMessage());
            return new JsonResponse(['error' => 'Data Saved in Database But Email not sent: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
  // Get All user which present in database 
    #[Route('/api/users', name: 'api_users', methods: ['GET'])]
public function getUsers(EntityManagerInterface $entityManager): JsonResponse
{
    $users = $entityManager->getRepository(User::class)->findAll();

    $data = [];
    foreach ($users as $user) {
        $data[] = [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'address' => $user->getAddress(),
            'role' => $user->getRole(),
        ];
    }

    return new JsonResponse($data, Response::HTTP_OK);
}
// Backup databse and save here /var/backup_(data).sql
#[Route('/api/backup', name: 'api_backup', methods: ['GET'])]
public function backupDatabase(): JsonResponse
{
    $backupFileName = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $backupFilePath = $this->getParameter('kernel.project_dir') . '/var/' . $backupFileName;

    $command = sprintf(
        'mysqldump -u%s -p%s %s > %s',
        $_ENV['DATABASE_USER'],
        $_ENV['DATABASE_PASSWORD'],
        $_ENV['DATABASE_NAME'],
        $backupFilePath
    );

    exec($command, $output, $returnVar);

    if ($returnVar !== 0) {
        return new JsonResponse(['error' => 'Backup failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse(['message' => 'Database backup created successfully', 'file' => $backupFileName], Response::HTTP_OK);
}
// Restore Data from Form data
#[Route('/api/restore', name: 'api_restore', methods: ['POST'])]
public function restoreDatabase(Request $request): JsonResponse
{
    $backupFile = $request->files->get('backup_file');

    if (!$backupFile) {
        return new JsonResponse(['error' => 'No backup file uploaded'], Response::HTTP_BAD_REQUEST);
    }

    $backupFilePath = $backupFile->getPathname();

    $command = sprintf(
        'mysql -u%s -p%s %s < %s',
        $_ENV['DATABASE_USER'],
        $_ENV['DATABASE_PASSWORD'],
        $_ENV['DATABASE_NAME'],
        $backupFilePath
    );

    exec($command, $output, $returnVar);

    if ($returnVar !== 0) {
        return new JsonResponse(['error' => 'Restore failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse(['message' => 'Database restored successfully'], Response::HTTP_OK);
}

// Delete User by Id 
#[Route('/api/delete_user/{id}', name: 'api_delete_user', methods: ['DELETE'])]
public function deleteUser(int $id, UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
{
    $user = $userRepository->find($id);

    if (!$user) {
        return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
    }

    try {
        $entityManager->remove($user);
        $entityManager->flush();
    } catch (\Exception $e) {
        return new JsonResponse(['error' => 'Failed to delete user'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse(['message' => 'User deleted successfully'], Response::HTTP_OK);
}
// Delete all user 
#[Route('/api/delete_users', name: 'api_delete_users', methods: ['DELETE'])]
public function deleteUsers(UserRepository $userRepository, EntityManagerInterface $entityManager): JsonResponse
{
    $users = $userRepository->findAll();

    if (!$users) {
        return new JsonResponse(['error' => 'No users found'], Response::HTTP_NOT_FOUND);
    }

    try {
        foreach ($users as $user) {
            $entityManager->remove($user);
        }
        $entityManager->flush();
    } catch (\Exception $e) {
        return new JsonResponse(['error' => 'Failed to delete users'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    return new JsonResponse(['message' => 'All users deleted successfully'], Response::HTTP_OK);
}
// Add User One By one 
    #[Route('/api/add_user', name: 'api_add_user', methods: ['POST'])]
    public function addUser(
        Request $request,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        MailerInterface $mailer,
        SerializerInterface $serializer
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $user = new User();
        $user->setName($data['name']);
        $user->setEmail($data['email']);
        $user->setUsername($data['username']);
        $user->setAddress($data['address']);
        if($data['role']=='USER'){
            $user->setRole(UserRole::USER);
        }else if($data['role']=='ADMIN'){
            $user->setRole(UserRole::ADMIN);
        }

        $errors = $validator->validate($user);

        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return new JsonResponse(['error' => $errorMessages], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $entityManager->persist($user);
            $entityManager->flush();
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Failed to add user'], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
     //   Send emails asynchronously
            $this->sendEmailAsync($mailer , $user);
        return new JsonResponse(['message' => 'User added successfully'], JsonResponse::HTTP_OK);
    }
}




