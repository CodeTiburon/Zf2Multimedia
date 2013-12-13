<?php

namespace Zf2Multimedia\Image;

use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

define('IMAGE_TRANSFORM_ERROR_UNSUPPORTED', 1);
define('IMAGE_TRANSFORM_ERROR_FAILED', 2);
define('IMAGE_TRANSFORM_ERROR_IO', 3);
define('IMAGE_TRANSFORM_ERROR_ARGUMENT', 4);
define('IMAGE_TRANSFORM_ERROR_OUTOFBOUND', 5);
define('OS_WINDOWS', (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'));

class Image implements ServiceLocatorAwareInterface
{
    const MAX_WIDTH = 3600;
    const MAX_HEIGHT = 3600;

    protected $_fileName;
    protected $_tempName;
    protected $_command;
    protected $_width = 0;
    protected $_height = 0;
    protected $_type = '';
    protected $_options = array(
        'quality' => 90,
        'scaleMethod' => 'smooth',
        'canvasColor' => array(255, 255, 255),
        'pencilColor' => array(0, 0, 0),
        'textColor' => array(0, 0, 0)
    );
    protected $_IMPath = '';

    /**
     * @var ServiceLocatorInterface
     */
    protected $serviceLocator;

    /**
     * @return ServiceLocatorInterface
     */
    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

    /**
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

//	public function __construct($fileName)
//	{
//		if (!file_exists($fileName))
//			throw new \Exception('Fail to open file '.$fileName);
//
//		$this->init($fileName);
//	}

    public function __destruct()
    {
        @unlink($this->_tempName);
    }

    public function setFileName($fileName)
    {
        $config = $this->serviceLocator->get('Configuration');
        if (isset($config->imagemagick) && isset($config->imagemagick->path)) {
            $this->_IMPath = $config->imagemagick->path;
        }

        $this->_command = array();
        $this->_fileName = $fileName;
        $this->_tempName = tempnam(sys_get_temp_dir(), 'ct_');
        copy($this->_fileName, $this->_tempName);
        $this->_identify();
    }

    public function __get($name)
    {
        if (in_array($name, array('width', 'height', 'type')))
            return $this->{'_' . $name};

        throw new \Exception('Wrong property name');
    }

    public function _identify()
    {
        $cmd = $this->_prepareCMD(
            $this->_IMPath,
            'identify',
            "-format %w:%h:%m " . escapeshellarg($this->_tempName . '[0]')
        );
        $exit = 0;

        exec($cmd, $res, $exit);

        foreach ($res as $key => $ress) {
            if ($key == 0) {
                if ($exit == 0) {
                    $data = explode(':', $ress);
                    $this->_width = (int)$data[0];
                    $this->_height = (int)$data[1];
                    $this->_type = strtolower($data[2]);
                } else {
                    throw new \Exception('Cannot fetch image or images details.');
                }
            } else break;
        }
    }

    public function prop_resize($width, $height)
    {
        $x_ratio = $width / ($this->_width);
        $y_ratio = $height / ($this->_height);

        $ratio = min($x_ratio, $y_ratio);
        $use_x_ratio = ($x_ratio == $ratio);
        $new_x = $use_x_ratio ? $width : floor($this->_width * $ratio);
        $new_y = !$use_x_ratio ? $height : floor($this->_height * $ratio);

        if (isset($this->_command['resize']))
            return trigger_error(
                'You cannot scale or resize an image more than once without calling save() or display()',
                E_USER_ERROR
            );

        $this->_command['resize'] = '-geometry ' . ((int)$new_x) . 'x' . ((int)$new_y) . '!';
        $this->_command['options'] = '-interlace line -colorspace rgb';

        return $this->_process();
    }

    public function resize($new_x, $new_y)
    {
        if (isset($this->_command['resize'])) {
            return trigger_error(
                'You cannot scale or resize an image more than once without calling save() or display()',
                E_USER_ERROR
            );
        }

        $this->_command['resize'] = '-geometry ' . ((int)$new_x) . 'x' . ((int)$new_y) . '!';

        return $this->_process();
    }

    public function resizeProportional($max_x, $max_y)
    {
        if (($this->_width / $this->_height) > ($max_x / $max_y)) {
            $new_x = $max_x;
            $new_y = intval($this->_height * ($max_x / $this->_width));
        } else {
            $new_y = $max_y;
            $new_x = intval($this->_width * ($max_y / $this->_height));
        }

        return $this->resize($new_x, $new_y);
    }

    public function resizeProportionalCropTo($size_x, $size_y)
    {
        if (($this->_width / $this->_height) > ($size_x / $size_y)) {
            $new_y = $size_y;
            $new_x = intval($this->_width * ($size_y / $this->_height));
        } else {
            $new_x = $size_x;
            $new_y = intval($this->_height * ($size_x / $this->_width));
        }

        if ($this->resize($new_x, $new_y)) {
            $crop_x = $crop_y = 0;
            if ($new_x > $new_y) {
                $crop_x = round(($this->_width - $size_x) / 2);
            } else {
                $crop_y = round(($this->_height - $size_y) / 2);
            }
            return $this->crop($size_x, $size_y, $crop_x, $crop_y);
        }

        return false;
    }

    public function resizeFitTo($new_size, $to_width = true)
    {
        if ($to_width) {
            $new_x = $new_size;
            $new_y = intval($this->_height * ($new_size / $this->_width));
        } else {
            $new_y = $new_size;
            $new_x = intval($this->_width * ($new_size / $this->_height));
        }

        return $this->resize($new_x, $new_y);
    }

    public function resizeWithFill($width, $height, $color)
    {
        $this->_command['resize'] =
            '-resize ' . $width . 'x' . $height .
            ' -background ' . $color .
            ' -gravity center' .
            ' -extent ' . $width . 'x' . $height;

        return $this->_process();
    }

    public function crop($width, $height, $x = 0, $y = 0, $repage = false)
    {
        $this->_command['crop'] = sprintf(
            "-crop %dx%d+%d+%d%s",
            (int)$width,
            (int)$height,
            (int)$x,
            (int)$y,
            $repage ? ' +repage' : ''
        );

        return $this->_process();
    }

    public function rotate($degrees)
    {
        $this->_command['rotate'] = '-rotate "' . (int)$degrees . '"';

        return $this->_process();
    }

    public function makeRoundedCorners($radius)
    {
        $this->_command['draw'] =
            '( +clone -channel matte -separate +channel -negate' .
            ' -draw ' . escapeshellarg(
                'fill black polygon  0,0 0,' . $radius . ' ' . $radius . ',0 fill white circle ' . $radius . ',' . $radius . ' ' . $radius . ',0'
            ) .
            ' ( +clone -flip ) -compose Multiply -composite' .
            ' ( +clone -flop ) -compose Multiply -composite )' .
            ' +matte -compose CopyOpacity -composite';

        return $this->_process();
    }

    public function thumbnailCutToFit($width, $height)
    {
        if ($this->_width >= $this->_height) {
            $particularCmd = '-resize "' . 2 * ((int)$width) . 'x<" ';
            if (!OS_WINDOWS)
                $particularCmd = '-resize ' . 2 * ((int)$width) . 'x< ';
            $this->_command['resize'] =
                '-resize x' . 2 * ((int)$height) . ' '
                . $particularCmd
                . '-resize 50% '
                . '-gravity center '
                . '-crop ' . ((int)$width) . 'x' . ((int)$height) . '+0+0 '
                . '+repage';
        } else {
            $particularCmd = '-resize "x' . 2 * ((int)$height) . '<" ';
            if (!OS_WINDOWS)
                $particularCmd = '-resize x' . 2 * ((int)$height) . '< ';
            $this->_command['thumbnail'] =
                '-resize ' . 2 * ((int)$width) . 'x '
                . $particularCmd
                . '-resize 50% '
                . '-gravity center '
                . '-crop ' . ((int)$width) . 'x' . ((int)$height) . '+0+0 '
                . '+repage';
        }

        return $this->_process();
    }

    public function _process()
    {
        $quality = $this->_getOption('quality', null, '90');

        $cmd = $this->_prepareCMD(
            $this->_IMPath,
            'convert',
            ' -quality ' . ((int)$quality) . ' '
            . escapeshellarg($this->_tempName . '[0]') . ' '
            . (OS_WINDOWS ? implode(' ', $this->_command) : escapeshellcmd(implode(' ', $this->_command)))
            . ' ' . $this->_type . ':' . escapeshellarg($this->_tempName) . ' 2>&1'
        );

        $exit = 0;
        $res = array();
        exec($cmd, $res, $exit);

        if ($exit == 0) {
            $this->_command = array();
            $this->_identify();
            return true;
        } else
            throw new \Exception(implode('. ', $res) . IMAGE_TRANSFORM_ERROR_IO);
    }

    public function saveTo($fileName, $type = '', $quality = null)
    {
        $type = strtoupper(($type == '') ? $this->_type : $type);

        switch ($type) {
            case 'JPEG':
                $type = 'JPG';
                break;
        }

        $options = array();
        if (!is_null($quality))
            $options['quality'] = $quality;

        $quality = $this->_getOption('quality', $options, '90');

        $cmd = $this->_prepareCMD(
            $this->_IMPath,
            'convert',
            ' -quality ' . ((int)$quality) . ' '
            . escapeshellarg($this->_tempName . '[0]') . ' '
            . $type . ':' . escapeshellarg($fileName) . ' 2>&1'
        );

        $exit = '0';
        $res = array();
        exec($cmd, $res, $exit);

        return ($exit == 0 ? true : trigger_error(implode('. ', $res) . IMAGE_TRANSFORM_ERROR_IO, E_USER_ERROR));
    }

    public function save($type = '', $quality = null)
    {
        return $this->saveTo($this->_fileName, $type, $quality);
    }

    protected function _getOption($name, $options = array(), $default = null)
    {
        $opt = array_merge($this->_options, (array)$options);
        return (isset($opt[$name])) ? $opt[$name] : $default;
    }

    protected function _prepareCMD($path, $command, $args = '')
    {
        if (!OS_WINDOWS || !preg_match('/\s/', $path)) {
            return $command . ' ' . $args;
        }

        return 'start /D "' . $path . '" /B ' . $command . ' ' . $args;
    }
}
