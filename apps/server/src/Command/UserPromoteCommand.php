<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:promote',
    description: 'Promote a user to admin role',
)]
class UserPromoteCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email of the user to promote')
            ->addOption('demote', 'd', InputOption::VALUE_NONE, 'Demote the user (remove admin role) instead of promoting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $demote = $input->getOption('demote');

        $user = $this->userRepository->findOneBy(['email' => $email]);

        if (null === $user) {
            $io->error(sprintf('User with email "%s" not found.', $email));

            return Command::FAILURE;
        }

        $roles = $user->getRoles();

        if ($demote) {
            $roles = array_filter($roles, fn (string $role) => 'ROLE_ADMIN' !== $role);
            $user->setRoles(array_values($roles));
            $this->entityManager->flush();

            $io->success(sprintf('User "%s" has been demoted (admin role removed).', $email));
        } else {
            if (in_array('ROLE_ADMIN', $roles, true)) {
                $io->warning(sprintf('User "%s" already has the admin role.', $email));

                return Command::SUCCESS;
            }

            $roles[] = 'ROLE_ADMIN';
            $user->setRoles($roles);
            $this->entityManager->flush();

            $io->success(sprintf('User "%s" has been promoted to admin.', $email));
        }

        return Command::SUCCESS;
    }
}
