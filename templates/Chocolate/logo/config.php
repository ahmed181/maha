<?php
/**
 * Logo Configuration File
 *
 * This is a PHP file that sets up variables specific for a template.
 * It can also be used to run PHP code for a template.
 *
 */

class Chocolate_logo extends LogoMaker
{
	/**
	 * TextFieldCount
	 * If a logo uses a by-line or similar, this can come in handy
	*/
	public $TextFieldCount = 2;

	/**
	 * Name of the recommended template to use this logo for.
	*/
	public $FileType = 'png';

	public function __construct()
	{
		parent::__construct();
		$this->Text[0] = 'Chocolate';
		$this->Text[1] = 'Boutique';
	}

	public function GenerateLogo()
	{
		$this->NewLogo($this->FileType); // defaults to png. can use jpg or gif as well
		$this->TransparentBackground = true;
		$this->FontPath = dirname(__FILE__) . '/fonts/';

		$imageHeight = 50;
		$textLeft = 0;
		$textSize = 28;

		// we need the height of the text box to position the image and then caculate the text position
		$t_box = $this->TextBox($this->Text[0], 'TrajanPro-Regular.otf', '291309', $textSize, 0, 0);

		if(strlen($this->Text[1]) > 0) {
			$secondText = $this->TextBox($this->Text[1], 'TrajanPro-Regular.otf', '291309', $textSize, 0, 0);

			if($t_box['width'] > $secondText['width']) {
				$leftPosition = (($t_box['width'] - $secondText['width'])/2);
				$firstTextLeftOffset = 0;

				if($leftPosition < 0) {
					// convert to a positive int
					$firstTextLeftOffset = ($leftPosition-($leftPosition)-($leftPosition))+20;
					$leftPosition = $firstTextLeftOffset-10;
				}
			} else {
				$leftPosition = 25;
				$firstTextLeftOffset = (($secondText['width']+50-$t_box['width'])/2);
			}
		}

		// determine the y position for the text
		$y_pos = 8+(($imageHeight - $t_box['height'])/2);

		if(strlen($this->Text[0]) > 0) {
			// AddText() - text, font, fontcolor, fontSize (pt), x, y, center on this width
			$text_position = $this->AddText($this->Text[0], 'TrajanPro-Regular.otf', '291309', $textSize, $textLeft+$firstTextLeftOffset, $y_pos);
		}

		if(strlen($this->Text[1]) > 0) {
			// put in our second bit of text

			$secondText = $this->TextBox($this->Text[1], 'TrajanPro-Regular.otf', '291309', $textSize, 0, 0);

			// we want to center our text, so we take the width of the first text and subtract the width of the second text and divide the remainer in two to get our left position

			$text_position2 = $this->AddText($this->Text[1], 'TrajanPro-Regular.otf', '7e5933', $textSize, $leftPosition, $text_position['bottom_right_y'] + 20);

			$this->AddImage(dirname(__FILE__) . '/graphic.jpg', $leftPosition-25,  $text_position['bottom_right_y'] + 17);
			$this->AddImage(dirname(__FILE__) . '/graphic.jpg', $text_position2['bottom_right_x'] +5,  $text_position['bottom_right_y'] + 17);

			$top_right = max($text_position['width'], $text_position2['width']+45);
			$imageHeight = 100;
		}
		else {
			$top_right = '200';
		}


		$this->SetImageSize($top_right+20, $imageHeight);

		return $this->MakeLogo();
	}
}