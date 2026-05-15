# Simpledynamic Installer

Установщик для создания новых проектов на [Simpleynamic](https://github.com/sergei-tsel/simpledynamic).

## Установка

Для установки необходимы PHP 8.5 и Composer

```bash
# Установить установщик через Composer
composer global require sergei-tsel/simpledynamic-installer

# Создать шаблон приложения одним из следующих способов
simpledynamic new                             # Интерактивный режим: спросит название и опции
simpledynamic new example-app --dev           # Установить dev-зависимости
simpledynamic new example-app --git           # Инициализировать git
simpledynamic new example-app --force         # Перезаписать папку, если существует
```
