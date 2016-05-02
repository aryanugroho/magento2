<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Model;

use Magento\Backend\App\ConfigInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Authentication;
use Magento\Customer\Model\AccountManagement;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Customer\Model\Data\CustomerSecure;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

class AuthenticationTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Magento\Backend\App\ConfigInterface | \PHPUnit_Framework_MockObject_MockObject
     */
    private $backendConfigMock;

    /**
     * @var \Magento\Customer\Model\CustomerRegistry | \PHPUnit_Framework_MockObject_MockObject
     */
    private $customerRegistryMock;

    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface | \PHPUnit_Framework_MockObject_MockObject
     */
    protected $encryptorMock;

    /**
     * @var CustomerRepositoryInterface | \PHPUnit_Framework_MockObject_MockObject
     */
    private $customerRepositoryMock;

    /**
     * @var \Magento\Customer\Model\Data\CustomerSecure | \PHPUnit_Framework_MockObject_MockObject
     */
    private $customerSecureMock;

    /**
     * @var \Magento\Customer\Model\Authentication
     */
    private $authentication;

    protected function setUp()
    {
        $this->backendConfigMock = $this->getMockBuilder(ConfigInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getValue'])
            ->getMockForAbstractClass();
        $this->customerRegistryMock = $this->getMock(
            CustomerRegistry::class,
            ['retrieveSecureData', 'retrieve'],
            [],
            '',
            false
        );
        $this->customerRepositoryMock = $this->getMockBuilder(CustomerRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->encryptorMock = $this->getMockBuilder(\Magento\Framework\Encryption\EncryptorInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->customerSecureMock = $this->getMock(
            CustomerSecure::class,
            [
                'getId',
                'getPasswordHash',
                'isCustomerLocked',
                'getFailuresNum',
                'getFirstFailure',
                'getLockExpires',
                'setFirstFailure',
                'setFailuresNum',
                'setLockExpires'
            ],
            [],
            '',
            false
        );
        $objectManagerHelper = new ObjectManagerHelper($this);

        $this->authentication = $objectManagerHelper->getObject(
            Authentication::class,
            [
                'customerRegistry' => $this->customerRegistryMock,
                'backendConfig' => $this->backendConfigMock,
                'customerRepository' => $this->customerRepositoryMock,
                'encryptor' => $this->encryptorMock,
            ]
        );
    }

    public function testProcessAuthenticationFailureLockingIsDisabled()
    {
        $customerId = 1;
        $this->backendConfigMock->expects($this->exactly(2))
            ->method('getValue')
            ->withConsecutive(
                [\Magento\Customer\Model\Authentication::LOCKOUT_THRESHOLD_PATH],
                [\Magento\Customer\Model\Authentication::MAX_FAILURES_PATH]
            )
            ->willReturnOnConsecutiveCalls(0, 0);
        $this->customerRegistryMock->expects($this->once())
            ->method('retrieveSecureData')
            ->with($customerId)
            ->willReturn($this->customerSecureMock);
        $this->authentication->processAuthenticationFailure($customerId);
    }

    public function testProcessAuthenticationFailureFirstAttempt()
    {
        $customerId = 1;
        $this->backendConfigMock->expects($this->exactly(2))
            ->method('getValue')
            ->withConsecutive(
                [\Magento\Customer\Model\Authentication::LOCKOUT_THRESHOLD_PATH],
                [\Magento\Customer\Model\Authentication::MAX_FAILURES_PATH]
            )
            ->willReturnOnConsecutiveCalls(10, 5);

        $this->customerRegistryMock->expects($this->once())
            ->method('retrieveSecureData')
            ->with($customerId)
            ->willReturn($this->customerSecureMock);
        $customerMock = $this->getMockBuilder(CustomerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->customerRepositoryMock->expects($this->once())
            ->method('getById')
            ->with($customerId)
            ->willReturn($customerMock);
        $this->customerRepositoryMock->expects($this->once())
            ->method('save')
            ->with($customerMock);

        $this->customerSecureMock->expects($this->once())->method('getFailuresNum')->willReturn(0);
        $this->customerSecureMock->expects($this->once())->method('getFirstFailure')->willReturn(0);
        $this->customerSecureMock->expects($this->once())->method('setFirstFailure');
        $this->customerSecureMock->expects($this->once())->method('setFailuresNum');

        $this->authentication->processAuthenticationFailure($customerId);
    }

    public function testProcessAuthenticationFailureLockExpired()
    {
        $customerId = 1;
        $this->backendConfigMock->expects($this->exactly(2))
            ->method('getValue')
            ->withConsecutive(
                [\Magento\Customer\Model\Authentication::LOCKOUT_THRESHOLD_PATH],
                [\Magento\Customer\Model\Authentication::MAX_FAILURES_PATH]
            )
            ->willReturnOnConsecutiveCalls(10, 5);

        $this->customerRegistryMock->expects($this->once())
            ->method('retrieveSecureData')
            ->with($customerId)
            ->willReturn($this->customerSecureMock);
        $customerMock = $this->getMockBuilder(CustomerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->customerRepositoryMock->expects($this->once())
            ->method('getById')
            ->with($customerId)
            ->willReturn($customerMock);
        $this->customerRepositoryMock->expects($this->once())
            ->method('save')
            ->with($customerMock);

        $date = new \DateTime();
        $date->modify('-100 second');
        $lockExpiresDate = $date->format('Y-m-d H:i:s');
        $date->modify('-400 second');
        $firstFailureDate = $date->format('Y-m-d H:i:s');
        $this->customerSecureMock->expects($this->once())->method('getFailuresNum')->willReturn(5);
        $this->customerSecureMock->expects($this->once())
            ->method('getFirstFailure')
            ->willReturn($firstFailureDate);
        $this->customerSecureMock->expects($this->once())
            ->method('getLockExpires')
            ->willReturn($lockExpiresDate);
        $this->customerSecureMock->expects($this->once())->method('setFirstFailure');
        $this->customerSecureMock->expects($this->once())->method('setLockExpires')->with(null);
        $this->customerSecureMock->expects($this->once())->method('setFailuresNum')->with(1);

        $this->authentication->processAuthenticationFailure($customerId);
    }

    public function testProcessAuthenticationFailureMaxNumberOfAttempts()
    {
        $customerId = 1;
        $date = new \DateTime();
        $date->modify('-500 second');
        $formattedDate = $date->format('Y-m-d H:i:s');
        $this->backendConfigMock->expects($this->exactly(2))
            ->method('getValue')
            ->withConsecutive(
                [\Magento\Customer\Model\Authentication::LOCKOUT_THRESHOLD_PATH],
                [\Magento\Customer\Model\Authentication::MAX_FAILURES_PATH]
            )
            ->willReturnOnConsecutiveCalls(10, 5);

        $this->customerRegistryMock->expects($this->once())
            ->method('retrieveSecureData')
            ->with($customerId)
            ->willReturn($this->customerSecureMock);
        $customerMock = $this->getMockBuilder(CustomerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->customerRepositoryMock->expects($this->once())
            ->method('getById')
            ->with($customerId)
            ->willReturn($customerMock);
        $this->customerRepositoryMock->expects($this->once())
            ->method('save')
            ->with($customerMock);

        $this->customerSecureMock->expects($this->once())->method('getFailuresNum')->willReturn(4);
        $this->customerSecureMock->expects($this->once())
            ->method('getFirstFailure')
            ->willReturn($formattedDate);
        $this->customerSecureMock->expects($this->once())->method('setLockExpires');
        $this->customerSecureMock->expects($this->once())->method('setFailuresNum')->with(5);

        $this->authentication->processAuthenticationFailure($customerId);
    }

    public function testUnlock()
    {
        $customerId = 1;
        $this->customerRegistryMock->expects($this->once())
            ->method('retrieveSecureData')
            ->with($customerId)
            ->willReturn($this->customerSecureMock);
        $customerMock = $this->getMockBuilder(CustomerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->customerRepositoryMock->expects($this->once())
            ->method('getById')
            ->with($customerId)
            ->willReturn($customerMock);
        $this->customerRepositoryMock->expects($this->once())
            ->method('save')
            ->with($customerMock);
        $this->customerSecureMock->expects($this->once())->method('setFailuresNum')->with(0);
        $this->customerSecureMock->expects($this->once())->method('setFirstFailure')->with(null);
        $this->customerSecureMock->expects($this->once())->method('setLockExpires')->with(null);
        $this->authentication->unlock($customerId);
    }

    /**
     * @return array
     */
    public function validatePasswordAndLockStatusDataProvider()
    {
        return [[true], [false]];
    }

    /**
     * @return void
     */
    public function testIsLocked()
    {
        $customerId = 7;

        $customerModelMock = $this->getMockBuilder(\Magento\Customer\Model\Customer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $customerModelMock->expects($this->once())
            ->method('isCustomerLocked');
        $this->customerRegistryMock->expects($this->once())
            ->method('retrieve')
            ->with($customerId)
            ->willReturn($customerModelMock);

        $this->authentication->isLocked($customerId);
    }

    /**
     * @param bool $result
     * @dataProvider validateCustomerPassword
     */
    public function testAuthenticate($result)
    {
        $customerId = 7;
        $password = '1234567';
        $hash = '1b2af329dd0';

        $customerMock = $this->getMock(
            'Magento\Customer\Api\Data\CustomerInterface',
            [],
            [],
            '',
            false
        );
        $this->customerRepositoryMock->expects($this->any())
            ->method('getById')
            ->willReturn($customerMock);

        $this->customerSecureMock->expects($this->any())
            ->method('getId')
            ->willReturn($customerId);

        $this->customerSecureMock->expects($this->once())
            ->method('getPasswordHash')
            ->willReturn($hash);

        $this->customerRegistryMock->expects($this->any())
            ->method('retrieveSecureData')
            ->with($customerId)
            ->willReturn($this->customerSecureMock);

        $this->encryptorMock->expects($this->once())
            ->method('validateHash')
            ->with($password, $hash)
            ->willReturn($result);

        if ($result) {
            $this->assertTrue($this->authentication->authenticate($customerId, $password));
        } else {
            $this->backendConfigMock->expects($this->exactly(2))
                ->method('getValue')
                ->withConsecutive(
                    [\Magento\Customer\Model\Authentication::LOCKOUT_THRESHOLD_PATH],
                    [\Magento\Customer\Model\Authentication::MAX_FAILURES_PATH]
                )
                ->willReturnOnConsecutiveCalls(1, 1);
            $this->customerSecureMock->expects($this->once())
                ->method('isCustomerLocked')
                ->willReturn(false);

            $this->customerRegistryMock->expects($this->once())
                ->method('retrieve')
                ->with($customerId)
                ->willReturn($this->customerSecureMock);

            $this->customerRepositoryMock->expects($this->once())
                ->method('save')
                ->willReturn($customerMock);

            $this->setExpectedException('\Magento\Framework\Exception\InvalidEmailOrPasswordException');
            $this->authentication->authenticate($customerId, $password);
        }
    }

    /**
     * @return array
     */
    public function validateCustomerPassword()
    {
        return [
            [true],
            [false],
        ];
    }
}
