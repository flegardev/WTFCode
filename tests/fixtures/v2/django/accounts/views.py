from .models import Account

def account_detail(request, account_id):
    return Account.objects.get(pk=account_id)
