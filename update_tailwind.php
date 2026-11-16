<?php
$files = glob("*.php");

$newConfig = <<<EOD
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        sky: {
                            50: '#f4f6f8',
                            100: '#e1e5ec',
                            200: '#c4ccd9',
                            300: '#9fabbe',
                            400: '#7587a1',
                            500: '#17365a',
                            600: '#112A46',
                            700: '#0c1e32',
                            800: '#081421',
                            900: '#040a11'
                        }
                    }
                }
            }
        }
    </script>
EOD;

foreach ($files as $file) {
    if ($file === 'update_tailwind.php')
        continue;

    $content = file_get_contents($file);
    $original = $content;

    // 1. Remove ANY `<script> tailwind.config ... </script>` block completely using [\s\S]*?
    $content = preg_replace('/<script>\s*tailwind\.config[\s\S]*?<\/script>\s*/i', '', $content);

    // 2. Inject the central configuration neatly right below the CDN script tag.
    if (strpos($content, 'https://cdn.tailwindcss.com') !== false) {
        $content = preg_replace('/(<script src="https:\/\/cdn\.tailwindcss\.com[^>]*><\/script>)\s*/i', "$1\n" . $newConfig . "\n", $content);
    }

    // 3. Fix the hardcoded hex values in `<style>` tags specifically used for sidebar
    $content = str_replace(
        ['background-color: #f0f9ff;', 'color: #0284c7;', 'border-right: 4px solid #0284c7;'],
        ['background-color: #f4f6f8;', 'color: #112A46;', 'border-right: 4px solid #112A46;'],
        $content
    );

    if ($content !== $original) {
        file_put_contents($file, $content);
        echo "Updated $file\n";
    }
}
?>