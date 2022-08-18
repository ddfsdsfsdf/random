<?php

namespace Pterodactyl\Services\Users;

use Ramsey\Uuid\Uuid;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Contracts\Auth\PasswordBroker;
use Pterodactyl\Notifications\AccountCreated;
use Pterodactyl\Contracts\Repository\UserRepositoryInterface;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

class UserCreationService
{
    private SettingsRepositoryInterface $settings;
    /**
     * @var \Illuminate\Database\ConnectionInterface
     */
    private $connection;

    /**
     * @var \Illuminate\Contracts\Hashing\Hasher
     */
    private $hasher;

    /**
     * @var \Illuminate\Contracts\Auth\PasswordBroker
     */
    private $passwordBroker;

    /**
     * @var \Pterodactyl\Contracts\Repository\UserRepositoryInterface
     */
    private $repository;

    /**
     * CreationService constructor.
     */
    public function __construct(ConnectionInterface $connection, Hasher $hasher, PasswordBroker $passwordBroker, UserRepositoryInterface $repository, SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
        $this->connection = $connection;
        $this->hasher = $hasher;
        $this->passwordBroker = $passwordBroker;
        $this->repository = $repository;
    }

    /**
     * Create a new user on the system.
     *
     * @return \Pterodactyl\Models\User
     *
     * @throws \Exception
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     */
    public function handle(array $data)
    {
        if (array_key_exists('password', $data) && !empty($data['password'])) {
            $data['password'] = $this->hasher->make($data['password']);
        }

        $this->connection->beginTransaction();
        if (empty($data['password'])) {
            $generateResetToken = true;
            $data['password'] = $this->hasher->make(str_random(30));
        }

        /** @var \Pterodactyl\Models\User $user */
        $user = $this->repository->create(array_merge($data, [
            'uuid' => Uuid::uuid4()->toString(),
        ]), true, true);

        if (isset($generateResetToken)) {
            $token = $this->passwordBroker->createToken($user);
        }

        if ($this->settings->get('jexactyl::approvals:webhook') === 'true' && strlen($this->settings->get('jexactyl::approvals:webhook_url')) > 0) {
            $name = $this->settings->get('settings::app:name', 'Jexactyl');
            $webhook_data = [
                'username' => 'Jexactyl',
                'avatar_url' => 'https://avatars.githubusercontent.com/u/91636558',
                'embeds' => [
                    [
                        'title' => $name . ' - Registration Request',
                        'color' => 11821768,
                        'description' => 'A new user account has been created on ' . $name,
                        'fields' => [
                            [
                                'name' => 'Username:',
                                'value' => $data['username'],
                            ],
                            [
                                'name' => 'Email:',
                                'value' => $data['email'],
                            ],
                            [
                                'name' => 'Approve:',
                                'value' => env('APP_URL') . '/admin/approvals',
                            ],
                        ],
                        'footer' => ['text' => 'Jexactyl', 'icon_url' => 'https://avatars.githubusercontent.com/u/91636558'],
                        'timestamp' => date('c'),
                    ],
                ],
            ];
            Http::withBody(json_encode($webhook_data), 'application/json')->post($this->settings->get('jexactyl::approvals:webhook_url'));
        }

        $this->connection->commit();
        $user->notify(new AccountCreated($user, $token ?? null));

        return $user;
    }
}
