#!/usr/bin/env perl
# rwho data collector daemon

use warnings;
use strict;
use constant PATH_UTMP => '/var/run/utmp';

use Getopt::Long qw(:config no_ignore_case bundling);
use Pod::Usage;
use POSIX qw(:errno_h :signal_h);
use Linux::Inotify2;
use Sys::Hostname;
use JSON;
use LWP::UserAgent;
use Data::Dumper;

my $notify_url = "http://equal.cluenet.org/rwho/server.php";
my $update_interval = 10*60;

my $verbose = 0;
my $do_fork = 0;
my $do_single = 0;
my $hide_root = 1;

my $my_hostname;
my $my_fqdn;
my $pid_periodic;

sub canon_hostname {
	my $host = shift;
	if (eval {require Socket::GetAddrInfo}) {
		debug("canon_hostname: using Socket::GetAddrInfo");
		my %hint = (flags => Socket::GetAddrInfo->AI_CANONNAME);
		my ($err, @ai) = Socket::GetAddrInfo::getaddrinfo($host, "", \%hint);
		# FIXME: print error messages when needed
		return $err ? $host : ((shift @ai)->{canonname} // $host);
	}
	elsif (eval {require Net::addrinfo}) {
		debug("canon_hostname: using Net::addrinfo");
		my $hint = Net::addrinfo->new(
			flags => Net::addrinfo->AI_CANONNAME);
		my $ai = Net::addrinfo::getaddrinfo($host, undef, $hint);
		return (ref $ai eq "Net::addrinfo") ? $ai->canonname : $host;
	}
	else {
		debug("canon_hostname: using \"getent hosts\"");
		open my $fd, "-|", "getent", "hosts", $host;
		my @ai = split(" ", <$fd>);
		close $fd;
		return $ai[1] // $host;
	}
}

# Run code in subprocess
# $pid = forked { ... };
sub forked(&) {
	my $sub = shift;
	my $pid = fork();
	if ($pid) {return $pid} else {exit &$sub}
}

# Read the utmp file
sub ut_dump {
	my @utmp = ();
	if (eval {require User::Utmp}) {
		debug("ut_dump: using User::Utmp");
		while (my $ent = User::Utmp::getutxent()) {
			if ($ent->{ut_type} == User::Utmp->USER_PROCESS) {
				push @utmp, {
					user => $ent->{ut_user},
					line => $ent->{ut_line},
					host => $ent->{ut_host},
					time => $ent->{ut_time},
				};
			}
		}
		User::Utmp::endutxent();
	}
	elsif (eval {require Sys::Utmp}) {
		debug("ut_dump: using Sys::Utmp");
		my $utmp = Sys::Utmp->new();
		while (my $ent = $utmp->getutent()) {
			if ($ent->user_process) {
				push @utmp, {
					user => $ent->ut_user,
					line => $ent->ut_line,
					host => $ent->ut_host,
					time => $ent->ut_time,
				};
			}
		}
		$utmp->endutent();
	}
	else {
		die "error: either User::Utmp or Sys::Utmp required\n";
	}
	return @utmp;
}

# "utmp changed" handler
sub update {
	my @data = ut_dump();
	for (@data) {
		$_->{uid} = scalar getpwnam $_->{user};
		$_->{host} =~ s/^::ffff://;
	}
	if ($hide_root) {
		@data = grep {$_->{user} ne "root"} @data;
	}
	debug("update: uploading ".scalar(@data)." items");
	upload("put", \@data);
}

# Upload data to server
sub upload {
	my ($action, $data) = @_;
	my $ua = LWP::UserAgent->new;
	my %data = (
		host => $my_hostname,
		fqdn => $my_fqdn,
		action => $action,
		utmp => encode_json($data),
	);
	my $resp = $ua->post($notify_url, \%data);
	if (!$resp->is_success) {
		print "error: ".$resp->status_line."\n";
	}
	debug("upload: ".$resp->status_line);
}

sub watch {
	my $inotify = Linux::Inotify2->new();
	$inotify->watch(PATH_UTMP, IN_MODIFY, sub {update});
	debug("watch: idling");
	1 while $inotify->poll;
}

sub cleanup {
	if (defined $pid_periodic) {
		debug("cleanup: killing poller");
		kill SIGTERM, $pid_periodic;
	}
	debug("cleanup: removing all records");
	upload("destroy", []);
}

sub debug {
	local $" = " ";
	$verbose and print "rwhod[$$]: @_\n";
}

## startup code
GetOptions(
	"fork" => \$do_fork,
	"help" => sub { pod2usage(1); },
	"i|interval=i" => \$update_interval,
	"man" => sub { pod2usage(-exitstatus => 0, -verbose => 2); },
	"root" => sub { $hide_root = 0; },
	"single" => \$do_single,
	"v|verbose" => \$verbose,
) or pod2usage(2);

if (!defined $notify_url) {
	die "error: notify_url not specified\n";
}

$my_hostname = hostname;
$my_hostname =~ s/\..*$//;
$my_fqdn = canon_hostname($my_hostname);
debug("identifying as \"$my_fqdn\" ($my_hostname)");

$SIG{INT} = \&cleanup;
$SIG{TERM} = \&cleanup;

chdir "/";

$0 = "rwhod";

if ($do_single) {
	debug("doing single update");
	$0 = "rwhod: single update";
	update();
	if ($do_fork) {
		warn "warning: --fork ignored in single mode\n";
	}
	exit();
}

debug("doing initial update");
update();

if ($do_fork) {
	my $pid = fork;
	if (!defined $pid) {
		die "$!";
	} elsif ($pid > 0) {
		debug("forked to $pid");
		print "$pid\n";
		exit;
	} elsif ($pid == 0) {
		my $sid = POSIX::setsid;
		if ($sid < 0) {
			warn "setsid failed: $!";
		}
		debug("running in background");
	}
}

if ($update_interval) {
	debug("starting poller");
	$pid_periodic = forked {
		$0 = "rwhod: periodic(${update_interval}s)";
		$SIG{INT} = "DEFAULT";
		$SIG{TERM} = "DEFAULT";
		while (1) {
			debug("poller: sleeping $update_interval seconds");
			sleep $update_interval;
			update();
		}
	};
}

debug("starting inotify watch");
$0 = "rwhod: inotify(".PATH_UTMP.")";
watch();

__END__

=head1 NAME

rwhod - remote-who collector daemon

=head1 SYNOPSIS

rwhod [options]

=head1 OPTIONS

=over 8

=item B<--fork>

Fork to background after initial update and print PID to stdout.

=item B<--help>

Obvious.

=item B<-i I<seconds>>, B<--interval=I<seconds>>

Periodic update every I<seconds> seconds (600 by default).

=item B<--man>

Display the manual page.

=item B<--root>

Include root logins.

=item B<--single>

Do a single update and exit.

=item B<-v>, B<--verbose>

Print informative messages.

=back

=head1 DEPENDENCIES

Perl 5.10, apparently.

B<C<getaddrinfo>>: C<Socket::GetAddrInfo>, C<Net::addrinfo>, or the C<getent> binary.

B<C<utmp> access>: C<Sys::Utmp> or C<User::Utmp>

B<C<inotify>>: C<Linux::Inotify2> for real-time C<utmp> monitoring.

B<HTTP>: C<JSON> and C<LWP::UserAgent>

=head1 BUGS

It's useless.

C<inotify> requirement makes the script unportable outside Linux.

Using C<getaddrinfo> just to find our own FQDN might be overkill when the rest of the world can use C<gethostbyaddr> on 127.0.0.1.

Data submission URL is hardcoded.

Hosts are only identified by their FQDN, so it's possible to upload fake data.

Incremental updates are not yet implemented (server-side support exists).

=cut
