import re
content = open('/root/mbfd-hub/resources/views/filament/admin/pages/equipment-intake.blade.php').read()
print('@this count:', content.count('@this'))
print('$wire count:', content.count('$wire'))
print('scanStatus count:', content.count('scanStatus'))
print('processVisionResult $wire call:', 'this.$wire.processVisionResult' in content)
print('handleScanError $wire call:', 'this.$wire.handleScanError' in content)
