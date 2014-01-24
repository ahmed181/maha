<?php

/* Snippets/MessageBox.html */
class __TwigTemplate_04ecc24d8012cbf20fe6bcafe3b875fc extends Twig_Template
{
    public function display(array $context)
    {
        // line 1
        echo "\t<div class=\"MessageBox MessageBox";
        echo twig_safe_filter((isset($context['MsgBox_Type']) ? $context['MsgBox_Type'] : null));
        echo "\">
\t\t";
        // line 2
        echo twig_safe_filter((isset($context['MsgBox_Message']) ? $context['MsgBox_Message'] : null));
        echo "
\t</div>";
    }

}
