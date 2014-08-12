<?php

namespace Vich\UploaderBundle\Tests\Storage;

use org\bovigo\vfs\vfsStream;
use Symfony\Component\HttpFoundation\File\File;

use Vich\UploaderBundle\Storage\FileSystemStorage;
use Vich\UploaderBundle\Tests\DummyEntity;
use Vich\UploaderBundle\Tests\TestCase;

/**
 * FileSystemStorageTest.
 *
 * @author Dustin Dobervich <ddobervich@gmail.com>
 */
class FileSystemStorageTest extends TestCase
{
    /**
     * @var \Vich\UploaderBundle\Mapping\PropertyMappingFactory $factory
     */
    protected $factory;

    /**
     * @var \Vich\UploaderBundle\Mapping\PropertyMapping
     */
    protected $mapping;

    /**
     * @var \Vich\UploaderBundle\Tests\DummyEntity
     */
    protected $object;

    /**
     * @var FileSystemStorage
     */
    protected $storage;

    /**
     * @var \org\bovigo\vfs\vfsStreamDirectory
     */
    protected $root;

    /**
     * Sets up the test.
     */
    public function setUp()
    {
        $this->factory = $this->getFactoryMock();
        $this->mapping = $this->getMappingMock();
        $this->object = new DummyEntity();
        $this->storage = new FileSystemStorage($this->factory);

        $this->factory
            ->expects($this->any())
            ->method('fromObject')
            ->with($this->object)
            ->will($this->returnValue(array($this->mapping)));

        // and initialize the virtual filesystem
        $this->root = vfsStream::setup('vich_uploader_bundle', null, array(
            'uploads' => array(
                'test.txt' => 'some content'
            ),
        ));
    }

    /**
     * Tests the upload method skips a mapping which has a non
     * uploadable property value.
     *
     * @expectedException   LogicException
     * @dataProvider        invalidFileProvider
     * @group               upload
     */
    public function testUploadSkipsMappingOnInvalid($file)
    {
        $this->mapping
            ->expects($this->once())
            ->method('getFile')
            ->will($this->returnValue($file));

        $this->mapping
            ->expects($this->never())
            ->method('hasNamer');

        $this->mapping
            ->expects($this->never())
            ->method('getNamer');

        $this->mapping
            ->expects($this->never())
            ->method('getFileName');

        $this->storage->upload($this->object, $this->mapping);
    }

    public function invalidFileProvider()
    {
        $file = new File('dummy.file', false);

        return array(
            // skipped because null
            array( null ),
            // skipped because not even a file
            array( new \DateTime() ),
            // skipped because not instance of UploadedFile
            array( $file ),
        );
    }

    /**
     * Test the remove method skips trying to remove a file whose file name
     * property value returns null.
     *
     * @dataProvider emptyFilenameProvider
     */
    public function testRemoveSkipsEmptyFilenameProperties($propertyValue)
    {
        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->will($this->returnValue($propertyValue));

        $this->mapping
            ->expects($this->never())
            ->method('getUploadDir');

        $this->storage->remove($this->object, $this->mapping);
    }

    public function emptyFilenameProvider()
    {
        return array(
            array( null ),
            array( '' ),
        );
    }

    /**
     * Test the remove method skips trying to remove a file that no longer exists
     */
    public function testRemoveSkipsNonExistingFile()
    {
        $this->mapping
            ->expects($this->once())
            ->method('getUploadDir')
            ->will($this->returnValue($this->getValidUploadDir()));

        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->will($this->returnValue('foo.txt'));

        $this->storage->remove($this->object, $this->mapping);
    }

    public function testRemove()
    {
        $this->mapping
            ->expects($this->once())
            ->method('getUploadDestination')
            ->will($this->returnValue($this->getValidUploadDir()));

        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->will($this->returnValue('test.txt'));

        $this->storage->remove($this->object, $this->mapping);
        $this->assertFalse($this->root->hasChild('uploads' . DIRECTORY_SEPARATOR . 'test.txt'));
    }

    /**
     * Test the resolve path method.
     */
    public function testResolvePath()
    {
        $this->mapping
            ->expects($this->once())
            ->method('getUploadDir')
            ->will($this->returnValue(''));

        $this->mapping
            ->expects($this->once())
            ->method('getUploadDestination')
            ->will($this->returnValue('/tmp'));

        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->will($this->returnValue('file.txt'));

        $this->factory
            ->expects($this->once())
            ->method('fromName')
            ->with($this->object, 'file_mapping')
            ->will($this->returnValue($this->mapping));

        $path = $this->storage->resolvePath($this->object, 'file_mapping');

        $this->assertEquals(sprintf('/tmp%sfile.txt', DIRECTORY_SEPARATOR), $path);
    }

    /**
     * Test the resolve uri
     *
     * @dataProvider resolveUriDataProvider
     */
    public function testResolveUri($uploadDir, $uri)
    {
        $this->mapping
            ->expects($this->once())
            ->method('getUploadDir')
            ->will($this->returnValue($uploadDir));

        $this->mapping
            ->expects($this->once())
            ->method('getUriPrefix')
            ->will($this->returnValue('/uploads'));

        $this->mapping
            ->expects($this->once())
            ->method('getFileName')
            ->will($this->returnValue('file.txt'));

        $this->factory
            ->expects($this->once())
            ->method('fromName')
            ->with($this->object, 'file_mapping')
            ->will($this->returnValue($this->mapping));

        $path = $this->storage->resolveUri($this->object, 'file_mapping');

        $this->assertEquals($uri, $path);
    }

    public function resolveUriDataProvider()
    {
        return array(
            array(
                '/abs/path/web/uploads',
                '/uploads/file.txt'
            ),
            array(
                'c:\abs\path\web\uploads',
                '/uploads/file.txt'
            ),
            array(
                '/abs/path/web/project/web/uploads',
                '/uploads/file.txt'
            ),
            array(
                '/abs/path/web/project/web/uploads/custom/dir',
                '/uploads/custom/dir/file.txt'
            ),
        );
    }

    /**
     *  Test that file upload moves uploaded file to correct directory and with correct filename
     */
    public function testUploadedFileIsCorrectlyMoved()
    {
        $file = $this->getUploadedFileMock();

        $file
            ->expects($this->any())
            ->method('getClientOriginalName')
            ->will($this->returnValue('filename.txt'));

        $this->mapping
            ->expects($this->once())
            ->method('getFile')
            ->with($this->object)
            ->will($this->returnValue($file));

        $this->mapping
            ->expects($this->once())
            ->method('getUploadDestination')
            ->will($this->returnValue('/dir'));

        $file
            ->expects($this->once())
            ->method('move')
            ->with('/dir/', 'filename.txt');

        $this->storage->upload($this->object, $this->mapping);
    }

    /**
     * Test file upload when filename contains directories
     * @dataProvider filenameWithDirectoriesDataProvider
     */
    public function testFilenameWithDirectoriesIsUploadedToCorrectDirectory($uploadDir, $dir, $filename, $expectedDir, $expectedFileName)
    {
        $file = $this->getUploadedFileMock();

        $namer = $this->getMock('Vich\UploaderBundle\Naming\NamerInterface');
        $namer
            ->expects($this->once())
            ->method('name')
            ->with($this->object, $this->mapping)
            ->will($this->returnValue($filename));

        $this->mapping
            ->expects($this->once())
            ->method('getNamer')
            ->will($this->returnValue($namer));

        $this->mapping
            ->expects($this->any())
            ->method('getFilePropertyName')
            ->will($this->returnValue('file'));

        $this->mapping
            ->expects($this->once())
            ->method('getFile')
            ->with($this->object)
            ->will($this->returnValue($file));

        $this->mapping
            ->expects($this->once())
            ->method('hasNamer')
            ->will($this->returnValue(true));

        $this->mapping
            ->expects($this->once())
            ->method('getUploadDestination')
            ->will($this->returnValue($uploadDir));

        $this->mapping
            ->expects($this->once())
            ->method('getUploadDir')
            ->with($this->object)
            ->will($this->returnValue($dir));

        $file
            ->expects($this->once())
            ->method('move')
            ->with($expectedDir, $expectedFileName);

        $this->storage->upload($this->object, $this->mapping);

    }

    public function filenameWithDirectoriesDataProvider()
    {
        return array(
            array(
                '/root_dir',
                'dir_1/dir_2',
                'filename.txt',
                '/root_dir/dir_1/dir_2',
                'filename.txt'
            ),
            array(
                '/root_dir',
                'dir_1/dir_2',
                'filename.txt',
                '/root_dir/dir_1/dir_2',
                'filename.txt'
            ),
        );
    }

    /**
     * Creates a mock factory.
     *
     * @return \Vich\UploaderBundle\Mapping\PropertyMappingFactory The factory.
     */
    protected function getFactoryMock()
    {
        return $this->getMockBuilder('Vich\UploaderBundle\Mapping\PropertyMappingFactory')
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * Creates a mapping mock.
     *
     * @return \Vich\UploaderBundle\Mapping\PropertyMapping The property mapping.
     */
    protected function getMappingMock()
    {
        return $this->getMockBuilder('Vich\UploaderBundle\Mapping\PropertyMapping')
            ->disableOriginalConstructor()
            ->getMock();
    }

    protected function getValidUploadDir()
    {
        return $this->root->url() . DIRECTORY_SEPARATOR . 'uploads';
    }
}
