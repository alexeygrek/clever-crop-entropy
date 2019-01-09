<?php

/*
 Grek I/O
*/

class CleverCropEntropy {

	protected static $start_time = 0.0;
	protected $originalImage = null;
	protected $baseDimension;

	public static function start() {

		self::$start_time = microtime ( true );

	}

	public static function mark() {

		$end_time = ( microtime ( true ) - self::$start_time ) * 1000;

		return sprintf ( "%.1fms", $end_time );

	}

	public function __construct ( $imagePath = null ) {

		if ( $imagePath ) {

			$this->setImage ( new \Imagick ( $imagePath ) );

		}

	}

	public function setImage ( \Imagick $image ) {

		$this->originalImage = $image;

		$this->setBaseDimensions (

			$this->originalImage->getImageWidth(),
			$this->originalImage->getImageHeight()

		);

	}

	protected function area ( \Imagick $image ) {

		$size = $image->getImageGeometry();

		return $size['height'] * $size['width'];

	}

	public function resizeAndCrop ( $targetWidth, $targetHeight ) {

		$crop = $this->getSafeResizeOffset ( $this->originalImage, $targetWidth, $targetHeight );

		$this->originalImage->resizeImage ( $crop['width'], $crop['height'], \Imagick::FILTER_CUBIC, .5 );

		$offset = $this->getSpecialOffset ( $this->originalImage, $targetWidth, $targetHeight );

		$this->originalImage->cropImage ( $targetWidth, $targetHeight, $offset['x'], $offset['y'] );

		return $this->originalImage;

	}

	protected function getSafeResizeOffset ( \Imagick $image, $targetWidth, $targetHeight ) {

		$source = $image->getImageGeometry();

		if ( ( $source['width'] / $source['height'] ) < ( $targetWidth / $targetHeight ) ) {

			$scale = $source['width'] / $targetWidth;

		} else $scale = $source['height'] / $targetHeight;

		return array ( 'width' => (int) ( $source['width'] / $scale ), 'height' => (int) ( $source['height'] / $scale ) );

	}

	protected function rgb2bw ( $r, $g, $b ) {

		return ( $r * 0.299 ) + ( $g * 0.587 ) + ( $b * 0.114 );

	}

	protected function getEntropy ( $histogram, $area ) {

		$value = 0.0;

		$colors = count ( $histogram );

		for ( $idx = 0; $idx < $colors; $idx++ ) {

			$p = $histogram[$idx]->getColorCount() / $area;

			$value = $value + $p * log ( $p, 2 );

		}

		return -$value;

	}

	protected function setBaseDimensions ( $width, $height ) {

		$this->baseDimension = array ( 'width' => $width, 'height' => $height );

		return $this;

	}

	protected function getBaseDimension ( $key ) {

		if ( isset ( $this->baseDimension ) ) {

			return $this->baseDimension[$key];

		} elseif ( $key == 'width' ) {

			return $this->originalImage->getImageWidth();

		} else return $this->originalImage->getImageHeight();

	}

	protected function getRandomEdgeOffset ( \Imagick $original, $targetWidth, $targetHeight ) {

		$measureImage = clone ( $original );

		$measureImage->edgeimage ( 1 );
		$measureImage->modulateImage ( 100, 0, 100);
		$measureImage->blackThresholdImage ( "#101010" );

		return $this->getOffsetBalanced ( $targetWidth, $targetHeight );

	}

	public function getOffsetBalanced ( $targetWidth, $targetHeight ) {

		$size = $this->originalImage->getImageGeometry();

		$points = array();

		$halfWidth = ceil ( $size['width'] / 2 );
		$halfHeight = ceil ( $size['height'] / 2 );

		$clone = clone ( $this->originalImage );
		$clone->cropimage ( $halfWidth, $halfHeight, 0, 0 );
		$point = $this->getHighestEnergyPoint ( $clone );
		$points[] = array ( 'x' => $point['x'], 'y' => $point['y'], 'sum' => $point['sum'] );

		$clone = clone ( $this->originalImage );
		$clone->cropimage ( $halfWidth, $halfHeight, $halfWidth, 0 );
		$point = $this->getHighestEnergyPoint ( $clone );
		$points[] = array ( 'x' => $point['x'] + $halfWidth, 'y' => $point['y'], 'sum' => $point['sum'] );

		$clone = clone ( $this->originalImage );
		$clone->cropimage ( $halfWidth, $halfHeight, 0, $halfHeight );
		$point = $this->getHighestEnergyPoint ( $clone );
		$points[] = array ( 'x' => $point['x'], 'y' => $point['y'] + $halfHeight, 'sum' => $point['sum'] );

		$clone = clone ( $this->originalImage );
		$clone->cropimage ( $halfWidth, $halfHeight, $halfWidth, $halfHeight );
		$point = $point = $this->getHighestEnergyPoint ( $clone );
		$points[] = array ( 'x' => $point['x'] + $halfWidth, 'y' => $point['y'] + $halfHeight, 'sum' => $point['sum'] );

		$totalWeight = array_reduce (

			$points, function ( $result, $array ) { return $result + $array['sum']; }

		);

		$centerX = 0;
		$centerY = 0;

		$totalPoints = count ( $points );

		for ( $idx = 0; $idx < $totalPoints; $idx++ ) {

			$centerX += $points[$idx]['x'] * ( $points[$idx]['sum'] / $totalWeight );
			$centerY += $points[$idx]['y'] * ( $points[$idx]['sum'] / $totalWeight );

		}

		$topleftX = max ( 0, ( $centerX - $targetWidth / 2 ) );
		$topleftY = max ( 0, ( $centerY - $targetHeight / 2 ) );

		if ( $topleftX + $targetWidth > $size['width'] ) {

			$topleftX -= ( $topleftX + $targetWidth ) - $size['width'];

		}

		if ( $topleftY + $targetHeight > $size['height'] ) {

			$topleftY -= ( $topleftY + $targetHeight ) - $size['height'];

		}

		return array ( 'x' => $topleftX, 'y' => $topleftY );

	}

	protected function getHighestEnergyPoint ( \Imagick $image ) {

		$size = $image->getImageGeometry();

		$tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'image' . rand();

		$image->writeImage ( $tmpFile );

		$im = imagecreatefromjpeg ( $tmpFile );

		$xcenter = 0;
		$ycenter = 0;
		$sum = 0;

		$sampleSize = round ( $size['height'] * $size['width'] ) / 50;

		for ( $k=0; $k < $sampleSize; $k++ ) {

			$i = mt_rand ( 0, $size['width'] - 1 );
			$j = mt_rand ( 0, $size['height'] - 1 );

			$rgb = imagecolorat ( $im, $i, $j );
			$r = ( $rgb >> 16 ) & 0xFF;
			$g = ( $rgb >> 8 ) & 0xFF;
			$b = $rgb & 0xFF;

			$val = $this->rgb2bw ( $r, $g, $b );
			$sum += $val;

			$xcenter += ( $i + 1 ) * $val;
			$ycenter += ( $j + 1 ) * $val;

		}

		if ( $sum ) {

			$xcenter /= $sum;
			$ycenter /= $sum;

		}

		$point = array (

			'x' => $xcenter,
			'y' => $ycenter,
			'sum' => $sum / round ( $size['height'] * $size['width'] )

		);

		return $point;

	}

	protected function getSpecialOffset ( \Imagick $original, $targetWidth, $targetHeight ) {

		return $this->getEntropyOffsets ( $original, $targetWidth, $targetHeight );

	}

	protected function getEntropyOffsets ( \Imagick $original, $targetWidth, $targetHeight ) {

		$measureImage = clone ( $original );

		$measureImage->edgeimage ( 1 );
		$measureImage->modulateImage ( 100, 0, 100 );
		$measureImage->blackThresholdImage ( "#070707" );

		return $this->getOffsetFromEntropy ( $measureImage, $targetWidth, $targetHeight );

	}

	protected function getOffsetFromEntropy ( \Imagick $originalImage, $targetWidth, $targetHeight ) {

		$image = clone $originalImage;

		$image->blurImage ( 3, 2 );

		$leftX = $this->slice ( $image,$targetWidth, 'h' );
		$topY = $this->slice ( $image,$targetHeight, 'v' );

		return array ( 'x' => $leftX, 'y' => $topY );

	}

	protected function slice ( $image, $targetSize, $axis ) {

		$rank = array();

		$imageSize = $image->getImageGeometry();

		$originalSize = ( $axis == 'h' ? $imageSize['width'] : $imageSize['height'] );
		$longSize = ( $axis == 'h' ? $imageSize['height'] : $imageSize['width'] );

		if ( $originalSize == $targetSize ) {

			return 0;

		}

		$numberOfSlices = 25;

		$sliceSize = ceil ( ( $originalSize ) / $numberOfSlices );

		$requiredSlices = ceil ( $targetSize / $sliceSize );

		$start = 0;

		while ( $start < $originalSize ) {

			$slice = clone $image;

			if ( $axis === 'h' ) {

			$slice->cropImage ( $sliceSize, $longSize, $start, 0 );

			} else $slice->cropImage ( $longSize, $sliceSize, 0, $start );

			$rank[] = array ( 'offset' => $start, 'entropy' => $this->grayscaleEntropy ( $slice ) );

			$start += $sliceSize;
		}

		$max = 0;
		$maxIndex = 0;

		for ( $i = 0; $i < $numberOfSlices - $requiredSlices; $i++ ) {

			$temp = 0;

			for ( $j = 0; $j < $requiredSlices; $j++ ) {

				$temp+= $rank[$i+$j]['entropy'];

			}

			if ( $temp > $max ) {

				$maxIndex = $i;
				$max = $temp;

			}

		}

		return $rank[$maxIndex]['offset'];

	}

	protected function getSafeZoneList() {

		return array();

	}

	protected function getPotential ( $position, $top, $sliceSize ) {

		$safeZoneList = $this->getSafeZoneList();

		$safeRatio = 0;

		if ( $position == 'top' || $position == 'left' ) {

			$start = $top;
			$end = $top + $sliceSize;

		} else {

			$start = $top - $sliceSize;
			$end = $top;

		}

		for ( $i = $start; $i < $end; $i++ ) {

			foreach ( $safeZoneList as $safeZone ) {

				if ( $position == 'top' || $position == 'bottom' ) {

					if ( $safeZone['top'] <= $i && $safeZone['bottom'] >= $i ) {

						$safeRatio = max ( $safeRatio, ( $safeZone['right'] - $safeZone['left'] ) );

					}

				} else {

					if ( $safeZone['left'] <= $i && $safeZone['right'] >= $i ) {

						$safeRatio = max ( $safeRatio, ( $safeZone['bottom'] - $safeZone['top'] ) );

					}

				}

			}

		}

		return $safeRatio;

	}

	protected function grayscaleEntropy ( \Imagick $image ) {

		$histogram = $image->getImageHistogram();

		return $this->getEntropy ( $histogram, $this->area ( $image ) );

	}

	protected function colorEntropy ( \Imagick $image ) {

		$histogram = $image->getImageHistogram();

		$newHistogram = array();

		$colors = count ( $histogram );

		for ( $idx = 0; $idx < $colors; $idx++ ) {

			$colors = $histogram[$idx]->getColor();

			$grey = $this->rgb2bw ( $colors['r'], $colors['g'], $colors['b'] );

			if ( !isset ( $newHistogram[$grey] ) ) {

				$newHistogram[$grey] = $histogram[$idx]->getColorCount();

			} else $newHistogram[$grey] += $histogram[$idx]->getColorCount();

		}

		return $this->getEntropy ( $newHistogram, $this->area ( $image ) );

	}

}

?>
