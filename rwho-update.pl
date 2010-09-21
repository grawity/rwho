#!/usr/bin/perl
# Debian: liblinux-inotify2-perl libjson-perl libjson-xs-perl libsys-utmp-perl
use warnings;
use strict;

use constant PATH_UTMP => '/var/run/utmp';
use constant {
	NOTIFY_URL => 'http://equal.cluenet.org/~grawity/misc/rwho.php',
	NOTIFY_SITE => 'equal.cluenet.org:80',
	NOTIFY_REALM => 'rwho',
};

use Linux::Inotify2;
use Sys::Hostname;
use JSON;
use LWP::UserAgent;

my $my_hostname;

sub ut_dump() {
	my @utmp = ();
	if (eval {require User::Utmp}) {
		while (my $ent = User::Utmp::getutxent()) {
			if ($ent->{ut_type} == User::Utmp->USER_PROCESS) {
				push @utmp, $ent;
			}
		}
		User::Utmp::endutxent();
	}
	elsif (eval {require Sys::Utmp}) {
		my $utmp = Sys::Utmp->new();
		while (my $ent = $utmp->getutent()) {
			if ($ent->user_process) {
				push @utmp, {
					ut_host => $ent->ut_host,
					ut_line => $ent->ut_line,
					ut_pid => $ent->ut_pid,
					ut_time => $ent->ut_time,
					ut_type => $ent->ut_type,
					ut_user => $ent->ut_user,
				};
			}
		}
		$utmp->endutent();
	}
	else {
		die "fatal: either User::Utmp or Sys::Utmp required\n";
	}
	return @utmp;
}

sub update() {
	my @keep = qw(ut_user ut_host ut_line ut_time);
	my @data = ();
	for my $entry (ut_dump()) {
		push @data, [@$entry{@keep}];
	}
	upload("put", \@data);
}

sub upload($$) {
	my ($action, $data) = @_;
	my $ua = LWP::UserAgent->new;
	my %data = (
		host => $my_hostname,
		action => $action,
		utmp => encode_json($data),
	);
	my $resp = $ua->post(NOTIFY_URL, \%data);
	if (!$resp->is_success) {
		print "error: ".$resp->status_line."\n";
	}
}

sub watch() {
	my $inotify = Linux::Inotify2->new();
	$inotify->watch(PATH_UTMP, IN_MODIFY, sub {
		update();
	});
	1 while $inotify->poll;
}

=foo
sub get_fqdn() {
	use Socket qw/pack_sockaddr_in inet_aton/;
	use Socket::GetAddrInfo qw/:newapi getnameinfo/;
	my $addr = pack_sockaddr_in(0, inet_aton("127.0.0.1"));
	my ($err, $host, $service) = getnameinfo($addr);
	return $host;
}

$my_hostname = get_fqdn();
=cut

$my_hostname = hostname;

$SIG{INT} = sub {
	upload("destroy", []);
};

print "sending initial update\n";
update();
print "watching ".PATH_UTMP." for modifications\n";
watch();
