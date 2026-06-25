# Deployment

1. Make sure the latest compiled assets are pushed `sail npm run prod`
1. If deploying to Infomaniak make sure proc_open is enabled
1. `sail php vendor/bin/dep deploy`
1. **⚠️ IMPORTANT:** After first deployment, double check `shared/.env` and `shared/public/robots.txt` contain the correct values

# Deployment with content

1. Make sure the latest compiled assets are pushed `sail npm run prod`
1. If deploying to Infomaniak make sure proc_open is enabled
1. `sail php vendor/bin/dep deploy -c`

# Sync storage to **DEV** server

```bash
rsync -avz storage/app/public/ pngj_fides@pngj.ftp.infomaniak.com:/home/clients/cd2ba32a6ae13cc0d40c45baa8613fe7/sites/fides.oplus.solutions/shared/storage/app/public
```

## Sync content to DEV server

```bash
rsync -avz content/ pngj_fides@pngj.ftp.infomaniak.com:/home/clients/cd2ba32a6ae13cc0d40c45baa8613fe7/sites/fides.oplus.solutions/shared/content
```

## Sync storage from DEV server to local

```bash
rsync -avz --delete pngj_fides@pngj.ftp.infomaniak.com:/home/clients/cd2ba32a6ae13cc0d40c45baa8613fe7/sites/fides.oplus.solutions/shared/storage/app/public/ storage/app/public
```

## Sync content from DEV server

```bash
rsync -avz --delete pngj_fides@pngj.ftp.infomaniak.com:/home/clients/cd2ba32a6ae13cc0d40c45baa8613fe7/sites/fides.oplus.solutions/shared/content/ content
```
