<?php
$file = "procurement.php";
$content = file_get_contents($file);

// 1. Fix CSS colors
$content = preg_replace('/background: linear-gradient\(135deg, #0ea5e9 0%, #0284c7 100%\);/', 'background: linear-gradient(135deg, #17365a 0%, #112A46 100%);', $content);
$content = preg_replace('/box-shadow: 0 10px 20px rgba\(14, 165, 233, 0\.3\);/', 'box-shadow: 0 10px 20px rgba(17, 42, 70, 0.3);', $content);
$content = preg_replace('/box-shadow: 0 4px 12px rgba\(14, 165, 233, 0\.3\);/', 'box-shadow: 0 4px 12px rgba(17, 42, 70, 0.3);', $content);

$content = str_replace([
    'class="w-full gradient-btn-green text-white py-3 rounded-xl font-bold shadow-lg text-base"',
    'focus:ring-green-400',
    'focus:border-green-400',
    'focus:ring-blue-400',
    'focus:border-blue-400',
    // also the badge for the tab container
    'from-green-500 to-green-600',
    'gradient-btn-green'
], [
    'class="w-full gradient-btn text-white py-3 rounded-xl font-bold shadow-lg text-base"',
    'focus:ring-sky-500',
    'focus:border-sky-500',
    'focus:ring-sky-500',
    'focus:border-sky-500',
    'from-sky-500 to-sky-600',
    'gradient-btn' // fallback
], $content);

file_put_contents($file, $content);
echo "Updated $file\n";
?>