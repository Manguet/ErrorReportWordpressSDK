<?php
/**
 * Script de build pour créer un package WordPress installable
 */

echo "=== Error Explorer WordPress Plugin Builder ===\n\n";

$pluginName = 'error-explorer';
$buildDir = __DIR__ . '/build';
$pluginDir = $buildDir . '/' . $pluginName;

// Nettoyer et créer le répertoire de build
if (is_dir($buildDir)) {
    shell_exec("rm -rf {$buildDir}");
}
mkdir($buildDir, 0755, true);
mkdir($pluginDir, 0755, true);

echo "📁 Création du package plugin...\n";

// Copier les fichiers essentiels
$filesToCopy = [
    'error-explorer.php',
    'README.md',
    'CHANGELOG.md',
    'readme.txt',
    'composer.json'
];

foreach ($filesToCopy as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        copy(__DIR__ . '/' . $file, $pluginDir . '/' . $file);
        echo "   ✅ Copié: {$file}\n";
    }
}

// Copier le répertoire src
function copyDirectory($src, $dst) {
    $dir = opendir($src);
    @mkdir($dst);
    while(false !== ( $file = readdir($dir)) ) {
        if (( $file != '.' ) && ( $file != '..' )) {
            if ( is_dir($src . '/' . $file) ) {
                copyDirectory($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

copyDirectory(__DIR__ . '/src', $pluginDir . '/src');
echo "   ✅ Copié: src/\n";

// Copier le répertoire languages
if (is_dir(__DIR__ . '/languages')) {
    copyDirectory(__DIR__ . '/languages', $pluginDir . '/languages');
    echo "   ✅ Copié: languages/\n";
}

// Installer les dépendances de production
echo "\n📦 Installation des dépendances...\n";
$oldCwd = getcwd();
chdir($pluginDir);
shell_exec('composer install --no-dev --optimize-autoloader --no-interaction');
chdir($oldCwd);
echo "   ✅ Dépendances installées\n";

// Créer un fichier de version
file_put_contents($pluginDir . '/version.txt', '1.0.0');

// Créer l'archive ZIP
echo "\n📦 Création de l'archive ZIP...\n";
$zipFile = $buildDir . '/error-explorer-wordpress-plugin.zip';
chdir($buildDir);
shell_exec("zip -r error-explorer-wordpress-plugin.zip {$pluginName}/ -x '*.git*' '*.DS_Store*'");
chdir($oldCwd);

echo "   ✅ Archive créée: {$zipFile}\n";

// Informations de fin
echo "\n=== Build terminé avec succès ! ===\n";
echo "📁 Répertoire de build: {$buildDir}\n";
echo "📦 Archive ZIP: {$zipFile}\n";
echo "📋 Fichiers inclus:\n";
foreach ($filesToCopy as $file) {
    echo "   - {$file}\n";
}
echo "   - src/ (avec toutes les classes PHP)\n";
echo "   - vendor/ (dépendances Composer optimisées)\n";
echo "   - version.txt\n";

echo "\n💡 Instructions d'installation:\n";
echo "1. Téléchargez error-explorer-wordpress-plugin.zip\n";
echo "2. Dans WordPress Admin → Extensions → Ajouter\n";
echo "3. Téléversez le fichier ZIP\n";
echo "4. Activez le plugin\n";
echo "5. Configurez dans Réglages → Error Explorer\n";