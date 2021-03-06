<?php
/**
 * BaconUser
 *
 * @link      http://github.com/Bacon/BaconUser For the canonical source repository
 * @copyright 2013 Ben Scholzen 'DASPRiD'
 * @license   http://opensource.org/licenses/BSD-2-Clause Simplified BSD License
 */

namespace BaconUser\Service;

use BaconUser\Entity\PasswordResetRequest;
use BaconUser\Entity\UserInterface;
use BaconUser\Options\PasswordResetOptionsInterface;
use BaconUser\Repository\PasswordResetRepositoryInterface;
use BaconUser\Repository\UserRepositoryInterface;
use DateTime;
use Doctrine\Common\Persistence\ObjectManager;
use Zend\EventManager\EventManager;
use Zend\EventManager\EventManagerAwareInterface;
use Zend\EventManager\EventManagerInterface;
use Zend\Crypt\Utils;
use Zend\Math;

/**
 * Service that allows to generate and verify reset password requests.
 */
class PasswordResetService implements EventManagerAwareInterface
{
    /**
     * Valid characters for token generation.
     */
    const HASH_CHAR_LIST = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * @var EventManagerInterface
     */
    protected $eventManager;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var UserRepositoryInterface
     */
    protected $userRepository;

    /**
     * @var PasswordResetRepositoryInterface
     */
    protected $passwordResetRepository;

    /**
     * @var PasswordResetOptionsInterface
     */
    protected $passwordResetOptions;

    /**
     * @param  ObjectManager                    $objectManager
     * @param  UserRepositoryInterface          $userRepository
     * @param  PasswordResetRepositoryInterface $passwordResetRepository
     * @param  PasswordResetOptionsInterface    $passwordResetOptions
     */
    public function __construct(
        ObjectManager $objectManager,
        UserRepositoryInterface $userRepository,
        PasswordResetRepositoryInterface $passwordResetRepository,
        PasswordResetOptionsInterface $passwordResetOptions
    ) {
        $this->objectManager           = $objectManager;
        $this->userRepository          = $userRepository;
        $this->passwordResetRepository = $passwordResetRepository;
        $this->passwordResetOptions    = $passwordResetOptions;
    }

    /**
     * Creates a new reset password request for a given email, and saves it to the database.
     *
     * @param  string $email
     * @return PasswordResetRequest
     */
    public function createResetPasswordRequest($email)
    {
        $user = $this->userRepository->findOneByEmail($email);

        if (!$user instanceof UserInterface) {
            return null;
        }

        // We first check if a token already exists for the given mail, so that we can reuse it.
        $passwordResetRequest = $this->passwordResetRepository->findOneByUser($user);
        $passwordResetRequest = $passwordResetRequest ?: new PasswordResetRequest($user);

        // If the token does not exist OR has expired (which is the case when the same reset password
        // request is reused).
        if ($passwordResetRequest->isExpired()) {
            $passwordResetRequest->setToken(Math\Rand::getString(24, static::HASH_CHAR_LIST));
        }

        $now              = new DateTime();
        $validityInterval = $this->passwordResetOptions->getTokenValidityInterval();

        $passwordResetRequest->setExpirationDate($now->add($validityInterval));

        $this->objectManager->persist($passwordResetRequest);
        $this->objectManager->flush($passwordResetRequest);

        // Trigger an event so that user can send a mail to the user in response.
        $this->getEventManager()->trigger(new PasswordResetEvent($passwordResetRequest));

        return $passwordResetRequest;
    }

    /**
     * Checks if a token is valid for a given email.
     *
     * This checks among other tings the time expiration. This method should be called before allowing
     * the user to restore a new password to prevent unwanted reset
     *
     * @param  string $email
     * @param  string $token
     * @return bool
     */
    public function isTokenValid($email, $token)
    {
        $user = $this->userRepository->findOneByEmail($email);

        if (!$user instanceof UserInterface) {
            return false;
        }

        $passwordResetRequest = $this->passwordResetRepository->findOneByUser($user);

        if (!$passwordResetRequest instanceof PasswordResetRequest) {
            return false;
        }

        if (!$passwordResetRequest->isExpired() && Utils::compareStrings($passwordResetRequest->getToken(), $token)) {
            return true;
        }

        return false;
    }

    /**
     * setEventManager(): defined by EventManagerAwareInterface.
     *
     * @see    EventManagerAwareInterface::setEventManager()
     * @param  EventManagerInterface $eventManager
     * @return void
     */
    public function setEventManager(EventManagerInterface $eventManager)
    {
        $eventManager->setIdentifiers(array(__CLASS__, get_class($this)));

        $this->eventManager = $eventManager;
    }

    /**
     * getEventManager(): defined by EventManagerAwareInterface.
     *
     * @see    EventManagerAwareInterface::getEventManager()
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (null === $this->eventManager) {
            $this->setEventManager(new EventManager());
        }

        return $this->eventManager;
    }
}
